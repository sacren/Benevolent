<?php

declare(strict_types=1);

use App\Blasts\BlastStatus;
use App\Blasts\SendBlast;
use App\Models\Blast;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Supporters\SubscriptionStatus;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Email;
use Tests\Support\QueueWorker;

/**
 * What a send actually does, driven through a real worker.
 *
 * The campaign suite covers who may commit a blast and what the commit writes.
 * This file covers the part that cannot be seen from one request in one
 * campaign: that a send reaches only its own campaign's people, signs itself as
 * that campaign, is answerable to that campaign, and cannot write to anybody
 * twice however many workers take the job.
 *
 * **Two campaigns throughout, never one (L-21).** A worker is the canonical
 * multi-campaign process -- it takes successive jobs for different campaigns in
 * one long-lived process that never restarts -- and every value a send touches
 * is one this project has already watched get captured once and served to
 * everybody: the database connection, the mail sender, the cached mailer, the
 * lock name. With a single campaign, "the first" and "the only" are the same
 * campaign and all of those pass.
 *
 * **The worker rather than a call to handle(), for the reason
 * Tests\Support\QueueWorker records**: a job's campaign is restored by a
 * listener on the JobProcessing event that only the worker raises, so calling
 * handle() directly would prove nothing about propagation, because the campaign
 * would still be whichever one the test left active.
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', [
        'name' => 'Harbor Cleanup',
        'domain' => 'harbor-cleanup.test',
        '--contact' => 'crew@harbor-cleanup.test',
    ]);
    Artisan::call('campaign:create', [
        'name' => 'Ridge Restoration',
        'domain' => 'ridge-restoration.test',
        '--contact' => 'trail@ridge-restoration.test',
    ]);

    config(['queue.default' => 'database']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Every message that really left, as the Symfony mail it was built into.
 *
 * Collected from the framework's own MessageSent event rather than from the
 * array transport's buffer, and that is not a preference. The mail bootstrapper
 * forgets every resolved mailer when tenancy reverts, which takes the transport
 * -- and its buffer -- with it, so a test reading the buffer after a worker had
 * finished would find an empty one and could not tell that from nothing having
 * been sent.
 *
 * **Returns an ArrayObject rather than an array, and that is not a style
 * choice.** PHP copies an array on return, so a helper that returned `$sent`
 * would hand the test an empty snapshot taken before the worker ran, and every
 * assertion about what was sent would read zero -- which is exactly how this
 * was first written and what nine failing tests reported. An object is shared
 * by handle, so what the listener appends is what the caller sees.
 *
 * @return ArrayObject<int, Email>
 */
function messagesSent(): ArrayObject
{
    /** @var ArrayObject<int, Email> $sent */
    $sent = new ArrayObject;

    Event::listen(MessageSent::class, function (MessageSent $event) use ($sent): void {
        $original = $event->sent->getOriginalMessage();

        if ($original instanceof Email) {
            $sent->append($original);
        }
    });

    return $sent;
}

/**
 * Give the active campaign some subscribed supporters and a committed blast.
 *
 * The blast arrives already queued, because committing is the campaign suite's
 * subject and handle() refuses a blast that is still a draft -- a send must not
 * be able to commit the blast it is carrying out.
 *
 * @param  list<string>  $addresses
 */
function committedBlastFor(array $addresses, string $subject = 'Save the harbour'): Blast
{
    foreach ($addresses as $address) {
        Supporter::factory()->create([
            'email' => $address,
            'subscription_status' => SubscriptionStatus::Subscribed,
        ]);
    }

    return Blast::factory()->queued()->create([
        'subject' => $subject,
        'body' => 'Come to the meeting on Thursday.',
    ]);
}

/**
 * The campaign as a job would find it.
 */
function sendingCampaign(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

test('a send reaches only its own campaign, and signs and answers as that campaign', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');
    $ridge = sendingCampaign('ridge-restoration');

    tenancy()->initialize($harbor);
    $harborBlast = committedBlastFor(['ama@harbor.test', 'bo@harbor.test'], 'Harbour meeting');
    SendBlast::dispatch($harborBlast, (string) $harbor->getKey());

    tenancy()->initialize($ridge);
    $ridgeBlast = committedBlastFor(['cai@ridge.test'], 'Ridge meeting');
    SendBlast::dispatch($ridgeBlast, (string) $ridge->getKey());

    // Both jobs are queued, not run. Ending tenancy is what makes this a real
    // test of propagation rather than of a connection that happened to still be
    // open: the worker below starts from central context, exactly as a separate
    // worker process would.
    tenancy()->end();

    QueueWorker::runNextJobs(2);

    expect($sent)->toHaveCount(3);

    $byRecipient = [];

    foreach ($sent as $message) {
        $byRecipient[$message->getTo()[0]->getAddress()] = $message;
    }

    expect(array_keys($byRecipient))->toEqualCanonicalizing([
        'ama@harbor.test', 'bo@harbor.test', 'cai@ridge.test',
    ]);

    // Each campaign's own name on the envelope, its own reply address, and its
    // own subject. Stated as one assertion per campaign *and* as a disagreement,
    // because "Harbor Cleanup signs as Harbor Cleanup" is satisfied by a process
    // in which nothing had been captured yet -- it is the second campaign
    // reading differently that catches a value served to everybody.
    $harborMessage = $byRecipient['ama@harbor.test'];
    $ridgeMessage = $byRecipient['cai@ridge.test'];

    expect($harborMessage->getFrom()[0]->getName())->toBe('Harbor Cleanup')
        ->and($ridgeMessage->getFrom()[0]->getName())->toBe('Ridge Restoration')
        ->and($harborMessage->getFrom()[0]->getName())->not->toBe($ridgeMessage->getFrom()[0]->getName())
        ->and($harborMessage->getReplyTo()[0]->getAddress())->toBe('crew@harbor-cleanup.test')
        ->and($ridgeMessage->getReplyTo()[0]->getAddress())->toBe('trail@ridge-restoration.test')
        ->and($harborMessage->getSubject())->toBe('Harbour meeting')
        ->and($ridgeMessage->getSubject())->toBe('Ridge meeting');

    // The from *address* is the platform's for both, which is D-15's other half:
    // sending as the campaign's own domain needs SPF and DKIM alignment and a
    // verified domain, so the reply path carries the campaign and the envelope
    // does not (deferral 18, unfired).
    expect($harborMessage->getFrom()[0]->getAddress())
        ->toBe($ridgeMessage->getFrom()[0]->getAddress())
        ->toBe((string) config('mail.from.address'));

    // And each campaign's record names only its own people.
    tenancy()->initialize($harbor);
    expect(DB::table('blast_recipients')->count())->toBe(2)
        ->and(Blast::query()->whereKey($harborBlast->getKey())->sole()->status)->toBe(BlastStatus::Sent);

    tenancy()->initialize($ridge);
    expect(DB::table('blast_recipients')->count())->toBe(1)
        ->and(Blast::query()->whereKey($ridgeBlast->getKey())->sole()->status)->toBe(BlastStatus::Sent);
});

test('the message carries the campaign as its letterhead, never the platform', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['ama@harbor.test']);
    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    $body = $sent[0]->getTextBody();

    // Deferral 21, answered by construction rather than by steering
    // `config('app.name')`: this is the module's own Mailable with its own
    // template, so the platform's name never enters the body. Steering the key
    // instead would also have renamed the browser title on every campaign page
    // and the name in the split auth layout, because all three read it.
    expect($body)->toContain('Come to the meeting on Thursday.')
        ->and($body)->toContain('Harbor Cleanup')
        ->and($body)->toContain('crew@harbor-cleanup.test')
        ->and($body)->not->toContain((string) config('app.name'));

    // The platform's name really is different from the campaign's, so the
    // assertion above is not satisfied by the two being the same string.
    expect(config('app.name'))->not->toBe('Harbor Cleanup');
});

test('a campaign with no reply address sends without one rather than answering to the platform', function (): void {
    $sent = messagesSent();

    Artisan::call('campaign:create', ['name' => 'Quiet Coalition']);
    $quiet = sendingCampaign('quiet-coalition');

    tenancy()->initialize($quiet);
    $blast = committedBlastFor(['dee@quiet.test']);
    SendBlast::dispatch($blast, (string) $quiet->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    // No reply path at all. Falling back to the platform's address would route
    // a supporter's answer to people who cannot act on it and never asked to
    // receive it, which is worse than a message that cannot be answered.
    expect($sent[0]->getReplyTo())->toBe([])
        ->and($sent[0]->getTextBody())->toContain('not monitored');
});

test('running the same send twice writes to nobody twice', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['ama@harbor.test', 'bo@harbor.test']);

    // Dispatched twice on purpose. This is not a double-click -- the controller
    // makes that impossible -- it is the state `retry_after` produces by
    // default: a send longer than 90 seconds is released back to the queue and
    // a second worker takes it while the first is still running.
    SendBlast::dispatch($blast, (string) $harbor->getKey());
    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJobs(2);

    // Two people, two messages, from two runs of the same send. The claim is an
    // insert against a unique index, so the second run finds every recipient
    // already taken and sends nothing.
    expect($sent)->toHaveCount(2);

    tenancy()->initialize($harbor);

    expect(DB::table('blast_recipients')->count())->toBe(2)
        ->and(DB::table('blast_recipients')->whereNotNull('sent_at')->count())->toBe(2);
});

test('a send that stops part-way resumes rather than starting again', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['ama@harbor.test', 'bo@harbor.test', 'cai@harbor.test']);

    // One supporter was already reached before the send died -- exactly the row
    // a killed worker leaves behind. Written directly, because the only other
    // way to produce it is to kill a process mid-job.
    $alreadyReached = Supporter::query()->where('email', 'ama@harbor.test')->sole();

    DB::table('blast_recipients')->insert([
        'blast_id' => $blast->getKey(),
        'supporter_id' => $alreadyReached->getKey(),
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    // Two messages, not three. **This is the whole reason blast_recipients
    // exists** (D-14): without it the only options are abandoning the send or
    // restarting it from the top, and restarting writes to everybody already
    // reached a second time.
    expect($sent)->toHaveCount(2)
        ->and(array_map(fn (Email $m): string => $m->getTo()[0]->getAddress(), $sent->getArrayCopy()))
        ->toEqualCanonicalizing(['bo@harbor.test', 'cai@harbor.test']);

    tenancy()->initialize($harbor);
    expect(DB::table('blast_recipients')->count())->toBe(3);
});

test('an unsubscribed supporter is left out even though the blast was written before they left', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['ama@harbor.test']);

    // Somebody who asked not to be contacted after the blast was composed and
    // committed. D-14's whole reason for storing a rule rather than a list: a
    // recipient list frozen at compose time would mail them anyway, which is the
    // one outcome bulk email must never produce.
    Supporter::factory()->create([
        'email' => 'gone@harbor.test',
        'subscription_status' => SubscriptionStatus::Unsubscribed,
    ]);

    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('ama@harbor.test');

    tenancy()->initialize($harbor);
    expect(DB::table('blast_recipients')->count())->toBe(1);
});

test('one refused address fails that message and not the send', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);

    // An address the mail transport will refuse. It stands for any complaint a
    // transport makes about one recipient -- an SMTP `550 5.1.1 <address>: user
    // unknown` is the same shape -- and it is the cheapest one to produce
    // without a provider.
    $blast = committedBlastFor(['ama@harbor.test', 'not a real address', 'cai@harbor.test']);

    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    // The other two still went. One bad row in a list of thousands is ordinary,
    // and abandoning the send over it would be far worse than skipping it.
    expect($sent)->toHaveCount(2);

    tenancy()->initialize($harbor);

    $refused = DB::table('blast_recipients')->whereNotNull('failure_reason')->sole();

    expect(DB::table('blast_recipients')->count())->toBe(3)
        ->and(DB::table('blast_recipients')->whereNotNull('sent_at')->count())->toBe(2)
        // **The address is not in the reason**, which is the same door Phase 1
        // found in a QueryException's inlined bindings, arriving from the other
        // side: there the database named the person, here the mail transport
        // does. Stored as given it would make this table hold a copy of an
        // address that an erasure nulls the key of but cannot clean the text of.
        ->and($refused->failure_reason)->not->toContain('not a real address')
        ->and($refused->failure_reason)->toContain('[the address]')
        // And it still says what went wrong, so this is not satisfied by a
        // reason that says nothing at all.
        ->and($refused->failure_reason)->toContain('addr-spec');

    // The send as a whole finished. A per-recipient failure is not a failed
    // blast, which is why the two carry separate reasons.
    expect(Blast::query()->whereKey($blast->getKey())->sole()->status)->toBe(BlastStatus::Sent);
});

test('the queued payload names no supporter, and central holds no campaign mail', function (): void {
    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['very.private.person@harbor.test']);
    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    $central = (string) config('tenancy.database.central_connection');
    $payload = (string) DB::connection($central)->table('jobs')->sole()->payload;

    // The job carries identifiers, never rows: a model property serializes as a
    // ModelIdentifier, while a plain string is written verbatim -- which is
    // exactly why the one plain string it carries is a campaign id.
    expect($payload)->not->toContain('very.private.person@harbor.test')
        ->and($payload)->toContain((string) $harbor->getKey())
        // Read out of the decoded payload rather than matched in the raw JSON,
        // where every backslash in the class name is escaped.
        ->and(json_decode($payload, true)['displayName'])->toBe(SendBlast::class)
        ->and(json_decode($payload, true)['data']['command'])->toContain('ModelIdentifier');
});

test('the send declares campaign-scoped overlap protection that a crashed worker cannot leave held', function (): void {
    $harbor = sendingCampaign('harbor-cleanup');
    $ridge = sendingCampaign('ridge-restoration');

    tenancy()->initialize($harbor);
    $harborBlast = committedBlastFor(['ama@harbor.test']);

    tenancy()->initialize($ridge);
    $ridgeBlast = committedBlastFor(['cai@ridge.test']);
    tenancy()->end();

    // **The configuration invariant behind the two behavioural tests below, and
    // it is not decoration.** Removing the middleware altogether makes those two
    // error inside their own helper -- `middleware()[0]` on an empty array --
    // rather than fail for the reason they name, which is a break red for the
    // wrong reason. This is the assertion that reports the removal itself.
    $harborJob = new SendBlast($harborBlast, (string) $harbor->getKey());
    $ridgeJob = new SendBlast($ridgeBlast, (string) $ridge->getKey());

    expect($harborJob->middleware())->toHaveCount(1)
        ->and($harborJob->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);

    $harborKey = $harborJob->middleware()[0]->getLockKey($harborJob);
    $ridgeKey = $ridgeJob->middleware()[0]->getLockKey($ridgeJob);

    // Blast ids restart at 1 in every campaign, so without the campaign in the
    // key these two strings would be identical -- one lock shared by every
    // campaign on the platform (L-27).
    expect($harborBlast->getKey())->toBe($ridgeBlast->getKey())
        ->and($harborKey)->not->toBe($ridgeKey)
        ->and($harborKey)->toContain((string) $harbor->getKey())
        ->and($ridgeKey)->toContain((string) $ridge->getKey());

    // And the lock expires. Without this a worker killed mid-send leaves the
    // lock held forever, and the blast can never be picked up again -- a send
    // stuck permanently by the mechanism added to make sending safer.
    expect($harborJob->middleware()[0]->expiresAfter)->toBeGreaterThan(0);
});

test('two campaigns do not share one send lock', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');
    $ridge = sendingCampaign('ridge-restoration');

    tenancy()->initialize($harbor);
    $harborBlast = committedBlastFor(['ama@harbor.test']);

    tenancy()->initialize($ridge);
    $ridgeBlast = committedBlastFor(['cai@ridge.test']);
    SendBlast::dispatch($ridgeBlast, (string) $ridge->getKey());
    tenancy()->end();

    // **L-27's measured harm, set up deliberately.** Blast ids restart at 1 in
    // every campaign, so these two blasts have the same id -- and cache locks
    // are not campaign-scoped even where the cache around them is, because the
    // container's cache alias escapes the tenancy wrapper's `__call` tagging and
    // a tag never reaches a lock name anyway. A key built from the blast id
    // alone would therefore be one lock shared by both campaigns.
    expect($harborBlast->getKey())->toBe($ridgeBlast->getKey());

    $harborLock = holdSendLock($harborBlast, $harbor);

    expect($harborLock->get())->toBeTrue();

    QueueWorker::runNextJob();

    // Ridge's send ran while Harbor's lock was held. With the campaign out of
    // the key it would have been released instead -- one campaign's blast
    // silently turned away by another campaign's, with no failure raised and no
    // row written.
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('cai@ridge.test');

    $harborLock->release();
});

test('one campaign cannot run its own send twice at once', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['ama@harbor.test']);
    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    // The positive beside the negative above, on the same mechanism (L-19).
    // "Ridge ran while Harbor's lock was held" is satisfied by a lock that never
    // holds anything at all, so this asserts the lock does stop the send it is
    // meant to stop.
    $held = holdSendLock($blast, $harbor);

    expect($held->get())->toBeTrue();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(0);

    tenancy()->initialize($harbor);
    expect(DB::table('blast_recipients')->count())->toBe(0);

    $held->release();
});

/**
 * Hold the lock a send for this blast in this campaign would take.
 *
 * **The key is asked of the middleware itself** rather than spelled out here,
 * so it cannot drift from the key the job actually uses -- a test hard-coding
 * the string would keep passing against a job whose key had changed shape,
 * which is the one thing the two tests above exist to notice.
 *
 * **And the lock is taken from the container's cache repository rather than
 * through the Cache facade, which is L-27 arriving in the test harness.**
 * WithoutOverlapping resolves `Illuminate\Contracts\Cache\Repository`; the
 * facade resolves the cache *manager*, which the tenancy package wraps. Under an
 * array store those are two different store objects holding two different lock
 * tables, so a lock taken through the facade is invisible to the middleware --
 * measured, after a lock that should have blocked a send did not.
 */
function holdSendLock(Blast $blast, Tenant $campaign): Lock
{
    $job = new SendBlast($blast, (string) $campaign->getKey());

    return app(CacheRepository::class)->lock($job->middleware()[0]->getLockKey($job), 60);
}
