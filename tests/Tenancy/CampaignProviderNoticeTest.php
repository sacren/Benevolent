<?php

declare(strict_types=1);

use App\MailOutcomes\BounceType;
use App\Models\BlastRecipient;
use App\Models\ProviderMessage;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Models\Unsubscribe;
use App\Supporters\SubscriptionStatus;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Events\TenancyInitialized;
use Tests\Support\SnsSigner;

/**
 * What a notice from the mail provider does when SNS delivers it.
 *
 * **Driven through HTTP, as SNS drives it, against two campaigns that hold the
 * same address (§3's pair).** A notice names a copy by the provider's message
 * id; the tests that matter are the ones where getting the campaign or the
 * copy wrong would still look plausible -- the same person in both campaigns,
 * two copies to one person.
 *
 * **The fixtures are real envelopes, re-signed.** tests/Fixtures/sns holds the
 * envelopes Step 2 captured from SNS, copied byte for byte with the account's
 * identifiers replaced; the Transient is `06`'s envelope with B8's real
 * `ooto` bounce block. Replacing an identifier breaks AWS's signature, so
 * Tests\Support\SnsSigner signs each one and its certificate is served at the
 * envelope's own `SigningCertURL` through the HTTP fake -- the production
 * certificate fetch runs in every test, and no request leaves the suite.
 *
 * **The host is read, not assumed (§3's harness note).** A test request
 * carries `.env`'s host, `laravel.local` here and `localhost` in CI, both of
 * them central; so the host is always written into the URL, and the pinning
 * test sends to a campaign's host and to one nobody registered as well.
 */
beforeEach(function (): void {
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);

    config(['services.ses.notifications_topic_arn' => SnsSigner::fixture('delivery')['TopicArn']]);

    // AWS's certificate endpoint, answering with the suite's own certificate
    // unless a test has queued a failure first. A queue rather than a second
    // fake, because the HTTP fake answers with the first stub registered.
    $this->certificateFailures = new SplQueue;
    $failures = $this->certificateFailures;

    Http::preventStrayRequests();
    Http::fake([SnsSigner::fixture('delivery')['SigningCertURL'] => function ($request) use ($failures) {
        if ($failures->isEmpty()) {
            return Http::response(SnsSigner::certificate());
        }

        $failure = $failures->dequeue();

        return $failure instanceof Closure ? $failure($request) : $failure;
    }]);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * A fixture's envelope, about the copy the provider called $providerMessageId,
 * with any field of its SES notice changed, signed by $signer.
 *
 * @param  array<string, mixed>  $changes  dot paths into the decoded `Message`
 */
function noticeBody(string $fixture, string $providerMessageId, array $changes = [], string $signer = 'aws'): string
{
    $envelope = SnsSigner::fixture($fixture);
    $notice = json_decode($envelope['Message'], true, flags: JSON_THROW_ON_ERROR);

    data_set($notice, 'mail.messageId', $providerMessageId);

    foreach ($changes as $path => $value) {
        data_set($notice, $path, $value);
    }

    $envelope['Message'] = json_encode($notice, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return SnsSigner::sign($envelope, $signer);
}

/**
 * POST a body to the intake as SNS does: raw JSON, text/plain, no cookie.
 */
function postToIntake(string $body, ?string $host = null): TestResponse
{
    $host ??= (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    return test()->call('POST', 'http://'.$host.'/mail-outcomes/sns', server: [
        'CONTENT_TYPE' => 'text/plain; charset=UTF-8',
        'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => (string) (json_decode($body, true)['Type'] ?? ''),
    ], content: $body);
}

/**
 * A sent copy in $campaign to $email, keyed as unit 1's send keys it: the id
 * on the copy, and the id mapped to the campaign centrally. Returns the copy's
 * key.
 */
function keyedCopy(Tenant $campaign, string $email, string $providerMessageId): int
{
    tenancy()->initialize($campaign);

    $supporter = Supporter::query()->firstWhere('email', $email) ?? Supporter::factory()->create(['email' => $email]);
    $copy = BlastRecipient::factory()->forSupporter($supporter)->sent()->create(['provider_message_id' => $providerMessageId]);

    tenancy()->end();

    ProviderMessage::query()->forceCreate([
        'provider_message_id' => $providerMessageId,
        'tenant_id' => (string) $campaign->getKey(),
    ]);

    return $copy->getKey();
}

/**
 * What a copy says now, read and cast inside its own campaign -- a datetime
 * cast asks the campaign's connection, which is gone once tenancy ends.
 *
 * @return array{delivered_at: ?Carbon, bounced_at: ?Carbon, bounce_type: ?BounceType, complained_at: ?Carbon}
 */
function copyNow(Tenant $campaign, int $copyId): array
{
    tenancy()->initialize($campaign);
    $copy = BlastRecipient::query()->findOrFail($copyId);
    $now = [
        'delivered_at' => $copy->delivered_at,
        'bounced_at' => $copy->bounced_at,
        'bounce_type' => $copy->bounce_type,
        'complained_at' => $copy->complained_at,
    ];
    tenancy()->end();

    return $now;
}

/**
 * Whether anything a notice could write has been written to this copy.
 */
function outcomesOn(Tenant $campaign, int $copyId): array
{
    tenancy()->initialize($campaign);
    $attributes = Arr::only(BlastRecipient::query()->findOrFail($copyId)->getAttributes(), ['delivered_at', 'bounced_at', 'bounce_type', 'complained_at']);
    tenancy()->end();

    return $attributes;
}

/**
 * A copy no notice has reached.
 */
function noOutcomes(): array
{
    return ['delivered_at' => null, 'bounced_at' => null, 'bounce_type' => null, 'complained_at' => null];
}

function noticeCampaign(string $slug): Tenant
{
    return Tenant::query()->where('slug', $slug)->firstOrFail();
}

/**
 * A supporter in $campaign with this status, before any copy is sent to them.
 */
function supporterWithStatus(Tenant $campaign, string $email, SubscriptionStatus $status): void
{
    tenancy()->initialize($campaign);
    Supporter::factory()->create(['email' => $email, 'subscription_status' => $status]);
    tenancy()->end();
}

/**
 * The supporter's status now, and how many withdrawals the campaign records.
 *
 * @return array{status: SubscriptionStatus, withdrawals: int}
 */
function supporterNow(Tenant $campaign, string $email): array
{
    tenancy()->initialize($campaign);
    $now = [
        'status' => Supporter::query()->where('email', $email)->sole()->subscription_status,
        'withdrawals' => Unsubscribe::query()->count(),
    ];
    tenancy()->end();

    return $now;
}

test('a notice lands on the copy it names, in the campaign that sent it, and no other campaign is entered', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $ridge = noticeCampaign('ridge-restoration');

    // The same person in both campaigns -- the case a join by address would
    // credit to the wrong one, or to both.
    $harborCopy = keyedCopy($harbor, 'ama@example.test', 'id-harbor');
    $ridgeCopy = keyedCopy($ridge, 'ama@example.test', 'id-ridge');

    $entered = new ArrayObject;
    Event::listen(TenancyInitialized::class, fn (TenancyInitialized $event) => $entered->append($event->tenancy->tenant->getTenantKey()));

    // Addressed to the person both campaigns hold, as a real notice would be.
    postToIntake(noticeBody('delivery', 'id-harbor', ['mail.destination' => ['ama@example.test']]))->assertOk();

    // Read before the assertions below enter both campaigns themselves.
    $enteredByTheNotice = $entered->getArrayCopy();

    expect($enteredByTheNotice)->toBe([(string) $harbor->getKey()])
        ->and(tenancy()->initialized)->toBeFalse()
        ->and(outcomesOn($harbor, $harborCopy)['delivered_at'])->not->toBeNull()
        ->and(outcomesOn($ridge, $ridgeCopy))->toBe(noOutcomes());
});

test('a notice for the earlier of two copies to one person lands on that copy, not the latest', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');

    $earlier = keyedCopy($harbor, 'ama@example.test', 'id-first-blast');
    $later = keyedCopy($harbor, 'ama@example.test', 'id-second-blast');

    postToIntake(noticeBody('bounce-permanent', 'id-first-blast'))->assertOk();

    expect(copyNow($harbor, $earlier)['bounce_type'])->toBe(BounceType::Permanent)
        ->and(outcomesOn($harbor, $later))->toBe(noOutcomes());
});

test('each kind of notice sets its own column, at the time the provider gives', function (string $fixture, string $column, string $timestampPath, ?BounceType $bounceType): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    postToIntake(noticeBody($fixture, 'id-1'))->assertOk();

    $said = data_get(json_decode(SnsSigner::fixture($fixture)['Message'], true), $timestampPath);
    $outcomes = outcomesOn($harbor, $copy);

    // Only this kind's column, and the provider's moment rather than the
    // moment the intake heard of it -- a notice SNS retried for a minute still
    // says when the event happened. To the second, as `sent_at` is: Laravel
    // binds a date as `Y-m-d H:i:s`, so the delivery's `.070` is cut in PHP.
    expect(array_keys(array_filter($outcomes)))->toEqualCanonicalizing(array_filter([$column, $bounceType ? 'bounce_type' : null]))
        ->and(copyNow($harbor, $copy)[$column]->equalTo(Carbon::parse($said)->startOfSecond()))->toBeTrue()
        ->and(copyNow($harbor, $copy)['bounce_type'])->toBe($bounceType);
})->with([
    'delivery' => ['delivery', 'delivered_at', 'delivery.timestamp', null],
    'permanent bounce' => ['bounce-permanent', 'bounced_at', 'bounce.timestamp', BounceType::Permanent],
    'transient bounce' => ['bounce-transient', 'bounced_at', 'bounce.timestamp', BounceType::Transient],
    'complaint' => ['complaint', 'complained_at', 'complaint.timestamp', null],
]);

test('a notice delivered twice changes nothing the second time', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    postToIntake(noticeBody('delivery', 'id-1', ['delivery.timestamp' => '2026-09-03T10:00:00.000Z']))->assertOk();
    postToIntake(noticeBody('delivery', 'id-1', ['delivery.timestamp' => '2026-09-03T10:05:00.000Z']))->assertOk();

    expect(copyNow($harbor, $copy)['delivered_at']->toIso8601ZuluString())->toBe('2026-09-03T10:00:00Z');
});

test('a permanent bounce replaces a transient one, a transient one never replaces a permanent one, and the supporter is bounced either way', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $first = keyedCopy($harbor, 'ama@example.test', 'id-1');
    $second = keyedCopy($harbor, 'bo@example.test', 'id-2');

    // Transient, then Permanent: the address is now known not to accept mail.
    postToIntake(noticeBody('bounce-transient', 'id-1', ['bounce.timestamp' => '2026-09-03T10:00:00.000Z']))->assertOk();
    postToIntake(noticeBody('bounce-permanent', 'id-1', ['bounce.timestamp' => '2026-09-03T11:00:00.000Z']))->assertOk();

    // Permanent, then Transient: an auto-reply later says nothing new.
    postToIntake(noticeBody('bounce-permanent', 'id-2', ['bounce.timestamp' => '2026-09-03T10:00:00.000Z']))->assertOk();
    postToIntake(noticeBody('bounce-transient', 'id-2', ['bounce.timestamp' => '2026-09-03T11:00:00.000Z']))->assertOk();

    expect(copyNow($harbor, $first)['bounce_type'])->toBe(BounceType::Permanent)
        ->and(copyNow($harbor, $first)['bounced_at']->toIso8601ZuluString())->toBe('2026-09-03T11:00:00Z')
        ->and(copyNow($harbor, $second)['bounce_type'])->toBe(BounceType::Permanent)
        ->and(copyNow($harbor, $second)['bounced_at']->toIso8601ZuluString())->toBe('2026-09-03T10:00:00Z');

    // The Permanent that replaced a Transient changed the copy, so it reaches
    // the supporter too: the copy having been bounced before is not the gate.
    expect(supporterNow($harbor, 'ama@example.test')['status'])->toBe(SubscriptionStatus::Bounced)
        ->and(supporterNow($harbor, 'bo@example.test')['status'])->toBe(SubscriptionStatus::Bounced);
});

test('a permanent bounce makes the supporter bounced and a complaint unsubscribes them, and nothing else changes them', function (string $fixture, SubscriptionStatus $becomes): void {
    $harbor = noticeCampaign('harbor-cleanup');
    keyedCopy($harbor, 'ama@example.test', 'id-1');

    postToIntake(noticeBody($fixture, 'id-1'))->assertOk();

    // No withdrawal row for a complaint: `unsubscribes` counts links used, and
    // nobody used one (D-87).
    expect(supporterNow($harbor, 'ama@example.test'))->toBe(['status' => $becomes, 'withdrawals' => 0]);
})->with([
    'delivery' => ['delivery', SubscriptionStatus::Subscribed],
    'permanent bounce' => ['bounce-permanent', SubscriptionStatus::Bounced],
    'transient bounce' => ['bounce-transient', SubscriptionStatus::Subscribed],
    'complaint' => ['complaint', SubscriptionStatus::Unsubscribed],
]);

test('a notice moves a supporter only from subscribed', function (string $fixture, SubscriptionStatus $already): void {
    $harbor = noticeCampaign('harbor-cleanup');
    supporterWithStatus($harbor, 'ama@example.test', $already);
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    postToIntake(noticeBody($fixture, 'id-1'))->assertOk();

    // The copy still records what the provider said; only the person is left.
    expect(outcomesOn($harbor, $copy))->not->toBe(noOutcomes())
        ->and(supporterNow($harbor, 'ama@example.test')['status'])->toBe($already);
})->with([
    'a permanent bounce for somebody who asked to leave' => ['bounce-permanent', SubscriptionStatus::Unsubscribed],
    'a complaint about an address already bounced' => ['complaint', SubscriptionStatus::Bounced],
]);

test('a notice delivered again after an operator put the supporter back leaves them subscribed', function (string $fixture): void {
    $harbor = noticeCampaign('harbor-cleanup');
    keyedCopy($harbor, 'ama@example.test', 'id-1');

    postToIntake(noticeBody($fixture, 'id-1'))->assertOk();

    tenancy()->initialize($harbor);
    Supporter::query()->where('email', 'ama@example.test')->update(['subscription_status' => SubscriptionStatus::Subscribed->value]);
    tenancy()->end();

    // SNS's retry of the same notice: the copy already holds it, so the
    // supporter is not moved a second time over the operator's decision.
    postToIntake(noticeBody($fixture, 'id-1'))->assertOk();

    expect(supporterNow($harbor, 'ama@example.test')['status'])->toBe(SubscriptionStatus::Subscribed);
})->with(['bounce-permanent', 'complaint']);

test('a supporter write that fails leaves the copy unwritten, so SNS\'s retry can still make both', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    // The copy's write succeeds and the supporter's is refused after it.
    tenancy()->initialize($harbor);
    DB::statement('alter table supporters drop column subscription_status');
    tenancy()->end();

    Exceptions::fake();

    postToIntake(noticeBody('bounce-permanent', 'id-1'))->assertServerError();

    expect(outcomesOn($harbor, $copy))->toBe(noOutcomes());
});

test('a message SNS did not sign is refused and writes nothing', function (Closure $forge): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $ridge = noticeCampaign('ridge-restoration');
    $harborCopy = keyedCopy($harbor, 'ama@example.test', 'id-1');
    $ridgeCopy = keyedCopy($ridge, 'ama@example.test', 'id-2');

    postToIntake($forge(noticeBody('delivery', 'id-1')))->assertForbidden();

    expect(outcomesOn($harbor, $harborCopy))->toBe(noOutcomes())
        ->and(outcomesOn($ridge, $ridgeCopy))->toBe(noOutcomes());
})->with([
    'signed with another key' => [fn (string $body): string => noticeBody('delivery', 'id-1', signer: 'someone else')],
    'signature removed' => [fn (string $body): string => json_encode(Arr::except(json_decode($body, true), 'Signature'))],
    'body changed after signing' => [function (string $body): string {
        $envelope = json_decode($body, true);
        $envelope['Message'] = str_replace('id-1', 'id-2', $envelope['Message']);

        return json_encode($envelope, JSON_UNESCAPED_SLASHES);
    }],
    'certificate off AWS' => [function (string $body): string {
        $envelope = SnsSigner::fixture('delivery');
        $envelope['Message'] = json_decode($body, true)['Message'];
        $envelope['SigningCertURL'] = 'https://sns.us-west-2.amazonaws.com.evil.test/cert.pem';

        // Served there too, so the refusal is the domain check's and not a
        // failed fetch's.
        Http::fake([$envelope['SigningCertURL'] => Http::response(SnsSigner::certificate())]);

        return SnsSigner::sign($envelope);
    }],
    'not JSON' => [fn (string $body): string => 'not an SNS message'],
]);

/*
 * Step 4's probe, kept (D-90): four unsigned forgeries that each named a
 * certificate URL. Before D-90 the first three made the intake fetch that URL
 * from AWS before any signature was read, then answered 503 and reported the
 * failure -- about 9 KB of log a request, at the caller's choosing.
 *
 * Every URL is faked as AWS would answer it, so a fetch the intake should not
 * make is *recorded* rather than refused as a stray request: a stray request
 * throws a RuntimeException, which the intake answers 403, and the test would
 * then pass for the wrong reason.
 */
test('a forged message is refused without a fetch it can avoid, and leaves nothing reported', function (bool $namesThisTopic, string $certificateUrl, Closure $awsAnswer, bool $fetchExpected): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    Http::fake([$certificateUrl => $awsAnswer]);
    Exceptions::fake();

    // A notice that would write, if it were believed.
    $envelope = json_decode(noticeBody('delivery', 'id-1'), true);
    $envelope['TopicArn'] = $namesThisTopic ? $envelope['TopicArn'] : 'arn:aws:sns:us-west-2:123456789012:t';
    $envelope['SigningCertURL'] = $certificateUrl;
    $envelope['Signature'] = base64_encode('not a signature');

    postToIntake(json_encode($envelope, JSON_UNESCAPED_SLASHES))->assertForbidden();

    expect(outcomesOn($harbor, $copy))->toBe(noOutcomes());
    Exceptions::assertNothingReported();

    $fetched = fn ($request): bool => $request->url() === $certificateUrl;
    $fetchExpected ? Http::assertSent($fetched) : Http::assertNotSent($fetched);
})->with([
    // Refused at the topic, before any fetch.
    'another topic, on the pinned host' => [false, 'https://sns.us-west-2.amazonaws.com/SimpleNotificationService-probe-a.pem', fn () => Http::response('', 404), false],
    // The cost D-90 accepts: the fetch still happens, but the 404 is a refusal, not a report.
    'this topic, a path AWS does not serve' => [true, 'https://sns.us-west-2.amazonaws.com/SimpleNotificationService-probe-b.pem', fn () => Http::response('', 404), true],
    // Refused at the pinned host, before any fetch.
    'this topic, another region\'s host' => [true, 'https://sns.zzz-nowhere-9.amazonaws.com/x.pem', fn () => Http::failedConnection(), false],
    'this topic, a host off AWS' => [true, 'https://example.com/x.pem', fn () => Http::response(SnsSigner::certificate()), false],
]);

test('a message from another topic is refused, and so is every message when no topic is set', function (?string $topic): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    config(['services.ses.notifications_topic_arn' => $topic]);

    // Signed correctly: AWS's signature says AWS sent it, not that this
    // account's topic did.
    postToIntake(noticeBody('delivery', 'id-1'))->assertForbidden();

    expect(outcomesOn($harbor, $copy))->toBe(noOutcomes());
})->with([
    'another topic' => ['arn:aws:sns:us-west-2:123456789012:someone-elses-topic'],
    'unset' => [null],
    'empty' => [''],
]);

test('a notice naming nothing the product kept is answered, writes nothing and says nothing', function (): void {
    // An invitation, account mail, or mail sent before the intake existed:
    // none was keyed (D-89), so its id has no central row.
    $harbor = noticeCampaign('harbor-cleanup');
    $ridge = noticeCampaign('ridge-restoration');
    $harborCopy = keyedCopy($harbor, 'ama@example.test', 'id-1');
    $ridgeCopy = keyedCopy($ridge, 'ama@example.test', 'id-2');

    Log::spy();

    postToIntake(noticeBody('complaint', 'id-of-an-invitation'))->assertOk();

    expect(outcomesOn($harbor, $harborCopy))->toBe(noOutcomes())
        ->and(outcomesOn($ridge, $ridgeCopy))->toBe(noOutcomes());

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('a notice for a copy whose reader was erased writes nothing', function (): void {
    // D-86's amendment: the erasure forgets the id on the copy and leaves the
    // central row, so the notice reaches the campaign and finds no copy.
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    tenancy()->initialize($harbor);
    Supporter::query()->where('email', 'ama@example.test')->sole()->delete();
    tenancy()->end();

    postToIntake(noticeBody('bounce-permanent', 'id-1'))->assertOk();

    expect(ProviderMessage::query()->whereKey('id-1')->exists())->toBeTrue()
        ->and(outcomesOn($harbor, $copy))->toBe(noOutcomes());
});

test('a write that fails is answered 5xx so SNS retries, leaves no campaign entered, and never reports the id', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    keyedCopy($harbor, 'ama@example.test', 'id-that-must-not-be-logged');

    // The cheapest refusal to produce, standing for any the database makes.
    tenancy()->initialize($harbor);
    DB::statement('alter table blast_recipients drop column delivered_at');
    tenancy()->end();

    Exceptions::fake();

    postToIntake(noticeBody('delivery', 'id-that-must-not-be-logged'))->assertServerError();

    expect(tenancy()->initialized)->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'SQLSTATE 42703')
        && ! str_contains($exception->getMessage(), 'id-that-must-not-be-logged'));
});

test('only a central host reaches the intake', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    // A campaign's host and a host nobody registered find no route at all.
    postToIntake(noticeBody('delivery', 'id-1'), 'harbor-cleanup.test')->assertNotFound();
    postToIntake(noticeBody('delivery', 'id-1'), 'nobody-registered.test')->assertNotFound();

    expect(outcomesOn($harbor, $copy))->toBe(noOutcomes());

    // And every host in the central list does, because the list is a list.
    foreach (config('tenancy.central_domains') as $centralDomain) {
        postToIntake(noticeBody('delivery', 'id-1'), $centralDomain)->assertOk();
    }

    // More than one, so the loop above proves a route on every host rather than on
    // one; how many depends on APP_URL (CI's localhost folds into the fixed pair).
    expect(count(config('tenancy.central_domains')))->toBeGreaterThan(1)
        ->and(outcomesOn($harbor, $copy)['delivered_at'])->not->toBeNull();
});

test('a notice starts no session, sets no cookie, and meets no CSRF check', function (): void {
    // Production keeps sessions in the central database; the suite's own
    // array driver would hide a row being written.
    config(['session.driver' => 'database']);

    // **The CSRF half is read from the route, not from a response.** Laravel
    // skips its CSRF check whenever unit tests run, so no request here could
    // ever be refused 419; the middleware the route would actually run is
    // what can be asserted.
    $centralHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    $route = app('router')->getRoutes()->match(Request::create('http://'.$centralHost.'/mail-outcomes/sns', 'POST'));

    expect(app('router')->gatherRouteMiddleware($route))->not->toContain(PreventRequestForgery::class)
        ->not->toContain(StartSession::class);

    $harbor = noticeCampaign('harbor-cleanup');
    keyedCopy($harbor, 'ama@example.test', 'id-1');

    $response = postToIntake(noticeBody('delivery', 'id-1'));

    $response->assertOk();

    expect(DB::table('sessions')->count())->toBe(0)
        ->and($response->headers->getCookies())->toBe([]);
});

test('the signing certificate is fetched once and then cached', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    keyedCopy($harbor, 'ama@example.test', 'id-1');
    keyedCopy($harbor, 'bo@example.test', 'id-2');

    postToIntake(noticeBody('delivery', 'id-1'))->assertOk();
    postToIntake(noticeBody('delivery', 'id-2'))->assertOk();

    Http::assertSentCount(1);
});

test('a certificate AWS cannot serve is answered 5xx so SNS retries, and is not cached', function (Closure $failure): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    Exceptions::fake();
    $this->certificateFailures->enqueue($failure());

    postToIntake(noticeBody('delivery', 'id-1'))->assertStatus(503);

    expect(outcomesOn($harbor, $copy))->toBe(noOutcomes());

    // The retry SNS makes finds the certificate rather than a cached failure.
    postToIntake(noticeBody('delivery', 'id-1'))->assertOk();

    expect(outcomesOn($harbor, $copy)['delivered_at'])->not->toBeNull();
})->with([
    'an error from AWS' => [fn () => Http::response('', 500)],
    'no connection' => [fn () => Http::failedConnection()],
]);

test('a subscription confirmation is answered and its token logged, and the subscription is never confirmed from here', function (): void {
    Log::spy();

    $envelope = SnsSigner::fixture('subscription-confirmation');

    postToIntake(SnsSigner::sign($envelope))->assertOk();

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $line): bool => str_contains($line, $envelope['Token']));

    // The certificate was fetched and nothing else: SubscribeURL was never
    // visited, which would leave the subscription open to an unauthenticated
    // unsubscribe (D-85).
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'ConfirmSubscription'));
});

test('an unsubscribe confirmation is answered and does nothing, and a type nobody can verify is refused', function (): void {
    $harbor = noticeCampaign('harbor-cleanup');
    $copy = keyedCopy($harbor, 'ama@example.test', 'id-1');

    Log::spy();

    // No UnsubscribeConfirmation was ever captured (v0.9). Its signed fields
    // are a SubscriptionConfirmation's, by AWS's documentation, so this one is
    // the captured confirmation with its Type changed rather than a fixture.
    $unsubscribe = SnsSigner::fixture('subscription-confirmation');
    $unsubscribe['Type'] = 'UnsubscribeConfirmation';

    postToIntake(SnsSigner::sign($unsubscribe))->assertOk();

    // A Type the validator has no signed-field list for cannot be verified,
    // so it is not known to be from SNS at all.
    $unknown = json_decode(noticeBody('delivery', 'id-1'), true);
    $unknown['Type'] = 'SomethingNew';

    postToIntake(SnsSigner::sign($unknown))->assertForbidden();

    expect(outcomesOn($harbor, $copy))->toBe(noOutcomes());

    Log::shouldNotHaveReceived('info');
});
