<?php

declare(strict_types=1);

use App\Blasts\BlastStatus;
use App\Blasts\SendBlast;
use App\Districts\ZctaDistricts;
use App\Models\Blast;
use App\Models\Segment;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\User;
use App\Supporters\SubscriptionStatus;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
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
    // Put back a relation deployDistrictRelation() stood aside, whether or not
    // the test that moved it passed.
    $shipped = resource_path(ZctaDistricts::fileFor(ZctaDistricts::SHIPPED_CONGRESS));

    if (file_exists($shipped.'.shipped')) {
        rename($shipped.'.shipped', $shipped);
    }

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
function committedBlastFor(array $addresses, string $subject = 'Save the harbor'): Blast
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
    $harborBlast = committedBlastFor(['ama@harbor.test', 'bo@harbor.test'], 'Harbor meeting');
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
        ->and($harborMessage->getSubject())->toBe('Harbor meeting')
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

/*
 * The way out, in the message (D-16).
 *
 * Step 4 shipped a module that could mail a campaign's whole list and offered
 * nobody a way off it, and marked the gap in the template itself. These are
 * what close it, and the last of them is the only test in this project that
 * follows the whole path a real supporter walks: a send, the message it
 * produced, the link inside it, and the row it changes.
 */

test('every message carries a way off the list, and no two carry the same one', function (): void {
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    $blast = committedBlastFor(['ama@harbor.test', 'bo@harbor.test']);
    $tokens = Supporter::query()->pluck('unsubscribe_token', 'email');
    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(2);

    foreach ($sent as $message) {
        $address = $message->getTo()[0]->getAddress();

        // **The one value that differs between two recipients' copies.** Until
        // this step the same bytes went to everybody; a per-supporter link
        // makes that false, which is addressing rather than personalization --
        // it says which envelope this copy belongs to and still says nothing
        // about who is reading it.
        expect($message->getTextBody())->toContain('/unsubscribe/'.$tokens[$address]);
    }

    // And they really are different links, so the assertion above is not
    // satisfied by one token shared between two people -- which is the schema
    // defect the unique index exists to refuse and which would look exactly
    // like a working send from here.
    expect($tokens['ama@harbor.test'])->not->toBe($tokens['bo@harbor.test']);
});

test('the link in a queued message points at the campaign\'s own host, never the platform\'s', function (): void {
    // **The hazard CampaignHostTenancyBootstrapper exists for, arriving at the
    // consumer its docblock predicted.** A queued job has no request to take a
    // root URL from, so route() would fall back to APP_URL -- the central host,
    // where campaign routes are deliberately unreachable -- and every
    // unsubscribe link ever mailed would 404.
    //
    // **Two campaigns, and the second is the one that matters (L-21).** A URL
    // generator that captured the first campaign's host and served it to the
    // next would send one campaign's supporters to another campaign's site,
    // where their token names nobody.
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');
    $ridge = sendingCampaign('ridge-restoration');

    tenancy()->initialize($harbor);
    SendBlast::dispatch(committedBlastFor(['ama@harbor.test']), (string) $harbor->getKey());
    tenancy()->end();

    tenancy()->initialize($ridge);
    SendBlast::dispatch(committedBlastFor(['bo@ridge.test']), (string) $ridge->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();
    QueueWorker::runNextJob();

    $bodyFor = function (string $address) use ($sent): string {
        foreach ($sent as $message) {
            if ($message->getTo()[0]->getAddress() === $address) {
                return $message->getTextBody();
            }
        }

        return '';
    };

    expect($bodyFor('ama@harbor.test'))->toContain('http://harbor-cleanup.test')
        ->and($bodyFor('ama@harbor.test'))->not->toContain('ridge-restoration.test')
        ->and($bodyFor('bo@ridge.test'))->toContain('http://ridge-restoration.test')
        ->and($bodyFor('bo@ridge.test'))->not->toContain('harbor-cleanup.test');

    // And neither carries the central host, which is where an unsteered
    // generator would have sent both.
    $centralHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    expect($bodyFor('ama@harbor.test'))->not->toContain($centralHost)
        ->and($bodyFor('bo@ridge.test'))->not->toContain($centralHost)
        // The central host really is a different string, so the two assertions
        // above are not satisfied by it matching the campaign's own.
        ->and($centralHost)->not->toBe('harbor-cleanup.test');
});

test('following the link from the message takes the supporter off the list, and the next blast misses them', function (): void {
    // **The whole path, end to end and in order**: a send, the message it
    // produced, the link a person would click in it, and the next send not
    // reaching them. Everything else in this step tests one joint of that; this
    // is the only test that walks all of it, and it is the definition of done
    // stated as behaviour.
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    SendBlast::dispatch(committedBlastFor(['ama@harbor.test', 'bo@harbor.test']), (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(2);

    // Read out of the message as a supporter reads it, rather than rebuilt from
    // the token -- which would test this file's own arithmetic instead of what
    // was actually mailed.
    $body = '';
    foreach ($sent as $message) {
        if ($message->getTo()[0]->getAddress() === 'ama@harbor.test') {
            $body = $message->getTextBody();
        }
    }

    expect(preg_match('~https?://\S+/unsubscribe/[0-9a-fA-F-]{36}~', $body, $matches))->toBe(1);

    // The unsubscribe limiter is caller-keyed and platform-wide by design
    // (L-24), so its budget carries across files in one process.
    app('cache')->driver()->flush();

    $this->post($matches[0])->assertRedirect();

    tenancy()->initialize($harbor);

    expect(Supporter::query()->whereEmailMatches('ama@harbor.test')->sole()->subscription_status)
        ->toBe(SubscriptionStatus::Unsubscribed)
        ->and(Supporter::query()->whereEmailMatches('bo@harbor.test')->sole()->subscription_status)
        ->toBe(SubscriptionStatus::Subscribed);

    // A second blast, written after they left. The audience is a rule evaluated
    // at send (D-14), so this is where that decision pays for itself.
    $second = Blast::factory()->queued()->create([
        'subject' => 'Second meeting',
        'body' => 'Another Thursday.',
    ]);

    SendBlast::dispatch($second, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(3);

    // The third message went to the one who stayed, and no fourth exists.
    expect($sent[2]->getTo()[0]->getAddress())->toBe('bo@harbor.test');

    tenancy()->initialize($harbor);

    expect(DB::table('blast_recipients')->where('blast_id', $second->getKey())->count())->toBe(1);

    tenancy()->end();
});

test('a second attempt at the same send cannot widen who it reaches', function (): void {
    // **The measurement that decided D-27(a), turned into a guard.** Before the
    // freeze this exact sequence delivered to somebody who was never in the
    // committed audience: the send resolves its rule inside handle(), `tries`
    // is 3 and `retry_after` is 90 seconds, so any send longer than that is
    // released and re-entered -- and the unique index on blast_recipients
    // refuses a *duplicate* while saying nothing at all about an audience that
    // grew between one attempt and the next.
    //
    // Driven through the worker rather than handle(), because a job's campaign
    // is restored by a listener only the worker raises.
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);

    $inTheCommittedAim = Supporter::factory()->create([
        'email' => 'ama@harbor.test',
        'postcode' => '90210',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);

    // Never in the committed audience, and admitted only by the edit below.
    Supporter::factory()->create([
        'email' => 'bo@harbor.test',
        'postcode' => '02139',
        'subscription_status' => SubscriptionStatus::Subscribed,
    ]);

    $segment = Segment::factory()->narrowedToPostcodes(['902'])->create(['name' => 'Dockside streets']);
    $blast = Blast::factory()->aimedAtSegment($segment)->queued()->create(['subject' => 'Dockside works begin']);

    SendBlast::dispatch($blast, (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJobs(1);

    expect($sent)->toHaveCount(1);

    // The segment is re-aimed somewhere disjoint between the two attempts --
    // an ordinary, permitted act, which is the whole reason the blast had to
    // keep its own copy rather than the edit being refused.
    tenancy()->initialize($harbor);
    $segment->update(['postcode_prefixes' => ['021']]);

    // The second attempt. Not a double-click, which the controller makes
    // impossible: this is what the queue itself does to a long send.
    SendBlast::dispatch($blast->fresh(), (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJobs(1);

    // Still one message, and still to the person the campaign committed to
    // reaching. Asserted on who rather than only how many, because a count
    // alone would pass if the retry had reached the wrong person instead.
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('ama@harbor.test');

    tenancy()->initialize($harbor);

    expect(DB::table('blast_recipients')->count())->toBe(1)
        ->and(DB::table('blast_recipients')->value('supporter_id'))->toBe($inTheCommittedAim->getKey())
        // And the segment really did move, so this is a difference rather than
        // two readings of an unchanged row.
        ->and($segment->fresh()->postcode_prefixes)->toBe(['021'])
        ->and($blast->fresh()->committed_prefixes)->toBe(['902']);
});

/**
 * Sign an Owner in for real, on their campaign's own hostname.
 *
 * Named apart from the identical helper in
 * CampaignBlastSegmentHttpIsolationTest because a global function cannot be
 * declared twice in one process and both files run in this suite.
 */
function signInForSend(string $host, string $email): void
{
    Auth::forgetGuards();

    test()->post('http://'.$host.'/login', [
        'email' => $email,
        'password' => 'password',
    ])->assertRedirect();
}

test('a district blast reaches the ZIP codes its seat claims and not the ones its boundary crosses', function (): void {
    // **Phase 4 exit criterion 3, asked of the send rather than of the query.**
    // The audience tests prove the rule selects the right rows; this proves the
    // messages went to the right inboxes, through the commit that froze the aim
    // and a worker that read the frozen list back -- the path a campaign
    // actually uses, with nothing standing in for anything.
    //
    // The three supporters are chosen to make the exclusion mean something.
    // 02141 lies wholly inside MA-07 and is claimed for it; 02139 straddles
    // MA-05 and MA-07, so the product refuses to place it and the blast must
    // miss that person even though they are two streets away; 90210 is in
    // another state entirely and is the control for "narrowed at all".
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);

    User::factory()->owner()->create(['email' => 'operator@harbor-cleanup.test']);

    foreach ([
        'placed@harbor.test' => '02141',
        'crossing@harbor.test' => '02139',
        'elsewhere@harbor.test' => '90210',
    ] as $address => $postcode) {
        Supporter::factory()->create([
            'email' => $address,
            'postcode' => $postcode,
            'subscription_status' => SubscriptionStatus::Subscribed,
        ]);
    }

    $segment = Segment::factory()->inDistrict('MA-07')->create(['name' => 'Home district list']);
    $blast = Blast::factory()->aimedAtSegment($segment)->create([
        'subject' => 'Constituent meeting',
        'body' => 'Come to the meeting on Thursday.',
    ]);

    tenancy()->end();

    // Committed through the route an operator uses, so the frozen ZIP codes are
    // the product's own reading of the relation rather than a list this test
    // wrote and then checked itself against.
    signInForSend('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    $this->post('http://harbor-cleanup.test/blasts/'.$blast->getKey().'/send')
        ->assertRedirect();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('placed@harbor.test');

    tenancy()->initialize($harbor);

    $committed = Blast::query()->sole();

    expect($committed->status)->toBe(BlastStatus::Sent)
        // What it froze, and the boundary-crossing ZIP code is not in it: the
        // claim is refused at the source rather than filtered at the send.
        ->and($committed->committed_zip_codes)->toContain('02141')
        ->and($committed->committed_zip_codes)->not->toContain('02139')
        ->and($committed->committed_prefixes)->toBeNull()
        // And the record of who it reached names one person.
        ->and(DB::table('blast_recipients')->count())->toBe(1);

    // **The control, in the same campaign in the same run.** Without it every
    // assertion above is satisfied by a campaign whose other two supporters
    // were never sendable -- unsubscribed, mis-seeded, or absent. A blast
    // aimed at nobody in particular reaches all three.
    $everyone = Blast::factory()->queued()->create([
        'subject' => 'Everyone',
        'body' => 'A second Thursday.',
    ]);

    SendBlast::dispatch($everyone, (string) $harbor->getKey());

    tenancy()->end();

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(4);

    $reached = [];

    foreach ($sent as $message) {
        $reached[] = $message->getTo()[0]->getAddress();
    }

    expect(array_slice($reached, 1))->toEqualCanonicalizing([
        'placed@harbor.test', 'crossing@harbor.test', 'elsewhere@harbor.test',
    ]);
});

/**
 * Commit Harbor's MA-07 blast through the route an operator uses, with one
 * supporter placed in the seat and one whose ZIP code crosses its boundary.
 *
 * Returns the committed blast's id, with tenancy ended and the job waiting.
 * Named for this file alone because a global function cannot be declared twice
 * in one process, and a single-file run would not show the collision.
 */
function commitHomeDistrictBlast(Tenant $harbor): int
{
    tenancy()->initialize($harbor);

    User::factory()->owner()->create(['email' => 'operator@harbor-cleanup.test']);

    foreach (['placed@harbor.test' => '02141', 'crossing@harbor.test' => '02139'] as $address => $postcode) {
        Supporter::factory()->create([
            'email' => $address,
            'postcode' => $postcode,
            'subscription_status' => SubscriptionStatus::Subscribed,
        ]);
    }

    $segment = Segment::factory()->inDistrict('MA-07')->create(['name' => 'Home district list']);
    $blast = Blast::factory()->aimedAtSegment($segment)->create();

    tenancy()->end();

    signInForSend('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    test()->post('http://harbor-cleanup.test/blasts/'.$blast->getKey().'/send')
        ->assertRedirect();

    return $blast->getKey();
}

/**
 * Stand in for a release that replaced the shipped relation: the shipped file
 * is stood aside and these ZCTAs written where it was, or nothing at all when
 * given null. afterEach() puts the shipped file back.
 *
 * App\Districts\ZctaDistricts reads the file on every call, so this is what a
 * deploy looks like to the code that reads it. The file itself is moved rather
 * than the resources directory, because resource_path() is derived from the
 * base path and cannot be pointed elsewhere on its own.
 *
 * @param  array<string, list<string>>|null  $zctas
 */
function deployDistrictRelation(?array $zctas): void
{
    $path = resource_path(ZctaDistricts::fileFor(ZctaDistricts::SHIPPED_CONGRESS));
    $shipped = ZctaDistricts::shipped();

    rename($path, $path.'.shipped');

    if ($zctas !== null) {
        file_put_contents($path, ZctaDistricts::encode($shipped->congress(), $shipped->publishedOn(), $shipped->source(), $shipped->sourceSha256(), $zctas));
    }
}

test('a map redrawn between the commit and the send moves nobody the blast was committed to', function (): void {
    // **D-38's whole claim, asked of a changed map rather than of the code
    // that makes it true.** Committing froze the ZIP codes MA-07 claimed; the
    // relation is then replaced by one in which 02141 has moved to MA-05 and
    // 02139 lies wholly inside MA-07 -- so a send that worked its audience out
    // again would reach the other supporter, and one that replays what it
    // froze reaches the same one.
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');
    $blastId = commitHomeDistrictBlast($harbor);

    deployDistrictRelation(['02139' => ['2507'], '02141' => ['2505']]);

    // The redeploy took effect, so a green result below is not a relation the
    // test failed to replace.
    expect(ZctaDistricts::shipped()->districtsTouching('02141'))->toBe(['2505']);

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('placed@harbor.test');

    tenancy()->initialize($harbor);

    expect(Blast::query()->findOrFail($blastId)->status)->toBe(BlastStatus::Sent);
});

test('a send never opens the district relation, so a worker pays nothing for it', function (): void {
    // **The worker reads the list the commit froze, and nothing else about
    // districts.** The relation is about 14 ms and 11 MB per read; a send that
    // opened it would pay that per job, for a list it already holds. Removing
    // the relation outright makes any read a failed send rather than a cost
    // nobody measures.
    $sent = messagesSent();

    $harbor = sendingCampaign('harbor-cleanup');
    $blastId = commitHomeDistrictBlast($harbor);

    deployDistrictRelation(null);

    expect(fn () => ZctaDistricts::shipped())->toThrow(RuntimeException::class);

    QueueWorker::runNextJob();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('placed@harbor.test');

    tenancy()->initialize($harbor);

    expect(Blast::query()->findOrFail($blastId)->status)->toBe(BlastStatus::Sent);
});

/**
 * Every column in the active campaign's database whose text holds the needle,
 * as `table.column`.
 *
 * Asks the schema rather than a list of columns somebody thought to name, so a
 * column added later is searched without anybody remembering to add it.
 *
 * @return list<string>
 */
function campaignColumnsHolding(string $needle): array
{
    $found = [];

    $columns = DB::connection('tenant')->select(
        "select table_name, column_name from information_schema.columns where table_schema = 'public' order by table_name, column_name"
    );

    foreach ($columns as $column) {
        $holding = DB::connection('tenant')
            ->table($column->table_name)
            ->whereRaw('position(? in lower(cast('.DB::connection('tenant')->getQueryGrammar()->wrap($column->column_name).' as text))) > 0', [strtolower($needle)])
            ->exists();

        if ($holding) {
            $found[] = $column->table_name.'.'.$column->column_name;
        }
    }

    return $found;
}

test('erasing a supporter a district blast reached leaves them nowhere in the campaign\'s database', function (): void {
    // **The seventh-home question, asked by running a deletion.** A district
    // send adds a column the erasure path does not touch --
    // `blasts.committed_zip_codes` -- and a recipient row. The frozen column is
    // filled from the relation rather than from anybody's row, so this is the
    // measurement behind that claim: after the supporter is removed through the
    // route an operator uses, neither their address nor their unsubscribe token
    // is in any column of any table, and what the campaign keeps is the
    // district's ZIP codes and a keyless record that a message went.

    $harbor = sendingCampaign('harbor-cleanup');
    $blastId = commitHomeDistrictBlast($harbor);

    QueueWorker::runNextJob();

    tenancy()->initialize($harbor);

    $placed = Supporter::query()->where('email', 'placed@harbor.test')->sole();
    $token = (string) $placed->unsubscribe_token;
    $frozen = Blast::query()->findOrFail($blastId)->committed_zip_codes;

    // Present before the deletion, so an empty result below is the deletion
    // rather than a search that could find nothing.
    expect(campaignColumnsHolding('placed@harbor.test'))->toBe(['supporters.email'])
        ->and(campaignColumnsHolding($token))->toBe(['supporters.unsubscribe_token']);

    tenancy()->end();

    test()->delete('http://harbor-cleanup.test/supporters/'.$placed->getKey())
        ->assertRedirect();

    tenancy()->initialize($harbor);

    expect(campaignColumnsHolding('placed@harbor.test'))->toBe([])
        ->and(campaignColumnsHolding($token))->toBe([])
        ->and(Blast::query()->findOrFail($blastId)->committed_zip_codes)->toBe($frozen)
        ->and(DB::table('blast_recipients')->whereNull('supporter_id')->whereNotNull('sent_at')->count())->toBe(1);
});

test('erasing a supporter who withdrew keeps the withdrawal and leaves nobody in it', function (): void {
    // **D-49's first half, asked by running a deletion against the schema.**
    // The same scan as the district erasure above, drawn from
    // information_schema, so `unsubscribes` is searched because it exists
    // rather than because anybody named it. The unsubscribe request writes
    // only unattributed rows while every link carries the supporter's token,
    // so the two rows are written straight to the table: one following this
    // supporter's copy of the blast, which no request can write yet, and one
    // the link could not attribute.
    $harbor = sendingCampaign('harbor-cleanup');

    tenancy()->initialize($harbor);
    User::factory()->owner()->create(['email' => 'operator@harbor-cleanup.test']);
    SendBlast::dispatch(committedBlastFor(['leaving@harbor.test', 'staying@harbor.test']), (string) $harbor->getKey());
    tenancy()->end();

    QueueWorker::runNextJob();

    tenancy()->initialize($harbor);

    $leaving = Supporter::query()->where('email', 'leaving@harbor.test')->sole();
    $token = (string) $leaving->unsubscribe_token;
    $copy = (int) DB::table('blast_recipients')->where('supporter_id', $leaving->getKey())->value('id');

    DB::table('unsubscribes')->insert([
        ['blast_recipient_id' => $copy, 'created_at' => now()],
        ['blast_recipient_id' => null, 'created_at' => now()],
    ]);

    // Present before the deletion, so an empty result below is the deletion
    // rather than a search that could find nothing.
    expect(campaignColumnsHolding('leaving@harbor.test'))->toBe(['supporters.email'])
        ->and(campaignColumnsHolding($token))->toBe(['supporters.unsubscribe_token']);

    tenancy()->end();

    signInForSend('harbor-cleanup.test', 'operator@harbor-cleanup.test');

    test()->delete('http://harbor-cleanup.test/supporters/'.$leaving->getKey())
        ->assertRedirect();

    tenancy()->initialize($harbor);

    expect(campaignColumnsHolding('leaving@harbor.test'))->toBe([])
        ->and(campaignColumnsHolding($token))->toBe([]);

    // **And what the campaign keeps is the withdrawal, still attributed.** The
    // row following their copy points at a recipient row that no longer says
    // who, which is D-10's answer arriving one table further out: the blast's
    // record of what happened after it stays true, and the person is gone
    // from it. Deleting the recipient row instead would take the withdrawal
    // with it, and the blast would afterwards claim nobody left.
    expect(DB::table('unsubscribes')->orderBy('id')->pluck('blast_recipient_id')->all())->toBe([$copy, null])
        ->and(DB::table('blast_recipients')->where('id', $copy)->value('supporter_id'))->toBeNull()
        ->and(DB::table('blast_recipients')->where('id', $copy)->value('sent_at'))->not->toBeNull();
});
