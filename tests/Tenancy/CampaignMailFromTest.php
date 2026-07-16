<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CampaignMailFromTenancyBootstrapper;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mime\Email;

/**
 * A campaign's mail should say which campaign sent it.
 *
 * Host-shaped values were already re-derived per campaign; identity-shaped ones
 * were not, so a campaign's password-reset mail carried the campaign's own
 * hostname in its link and the platform's name on its envelope. This covers the
 * half that needs no credentials -- the sender *name*. The sender *address*
 * stays the platform's deliberately, because sending as a campaign's own domain
 * needs SPF and DKIM alignment and a verified-domain flow rather than a config
 * change.
 *
 * Two campaigns throughout, never one. MailManager caches every mailer it
 * builds and stamps the sender on it at build time, so with a single campaign a
 * bootstrapper that never cleared that cache would look perfectly correct: the
 * first campaign to resolve a mailer is also the only one (L-21).
 */
beforeEach(function (): void {
    // Rebuild the central schema without a wrapping transaction (see the Tenancy
    // suite note in tests/Pest.php — CREATE DATABASE cannot run in a transaction).
    Artisan::call('migrate:fresh');

    Artisan::call('campaign:create', ['name' => 'Harbor Cleanup', 'domain' => 'harbor-cleanup.test']);
    Artisan::call('campaign:create', ['name' => 'Ridge Restoration', 'domain' => 'ridge-restoration.test']);
});

afterEach(function (): void {
    tenancy()->end();

    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * The sender name a message built right now would actually carry.
 *
 * Read off a real built message rather than out of config, because config being
 * right is not the claim -- the mailer is cached, so config and the message can
 * disagree, and that disagreement is the entire defect this guards.
 */
function senderNameOnBuiltMessage(): ?string
{
    $sent = Mail::mailer()->raw('probe', function ($message): void {
        $message->to('probe@example.test')->subject('probe');
    });

    $original = $sent?->getSymfonySentMessage()?->getOriginalMessage();

    if (! $original instanceof Email) {
        return null;
    }

    $from = $original->getFrom();

    return $from === [] ? null : $from[0]->getName();
}

test('each campaign signs its mail with its own name', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();
    $ridge = Tenant::query()->where('slug', 'ridge-restoration')->firstOrFail();

    // Resolving inside the first campaign is what arms the trap. From here the
    // manager holds a mailer already stamped `Harbor Cleanup`, and without the
    // cache being cleared it hands that same one to every campaign after.
    tenancy()->initialize($harbor);
    expect(senderNameOnBuiltMessage())->toBe('Harbor Cleanup');

    tenancy()->initialize($ridge);

    // Stated as one assertion so a later edit cannot drop half of it. Asserting
    // only that Ridge's mail says `Ridge Restoration` would be satisfied by a
    // process where nothing had been cached yet; the two campaigns disagreeing
    // is the claim.
    expect(senderNameOnBuiltMessage())->toBe('Ridge Restoration')
        ->and(senderNameOnBuiltMessage())->not->toBe('Harbor Cleanup');
});

test('entering a campaign does not inherit a mailer resolved centrally', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    $platformName = (string) config('mail.from.name');

    // The direction the campaign-to-campaign test cannot reach, and the reason
    // this bootstrapper needs a bootstrap() at all. Switching between two
    // campaigns already passes through revert(), since the package ends tenancy
    // before initializing a different one -- so revert() alone would cover that
    // case and bootstrap() would be dead code no break could redden. Nothing
    // calls revert() on the way *in* from central.
    expect(senderNameOnBuiltMessage())->toBe($platformName);

    tenancy()->initialize($harbor);

    expect($platformName)->not->toBe('Harbor Cleanup')
        ->and(senderNameOnBuiltMessage())->toBe('Harbor Cleanup');
});

test('leaving a campaign does not leave its sender name behind', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    $platformName = (string) config('mail.from.name');

    tenancy()->initialize($harbor);
    expect(senderNameOnBuiltMessage())->toBe('Harbor Cleanup');

    tenancy()->end();

    // The same defect pointing the other way: platform mail sent after a
    // campaign request -- a console command, the next job a worker picks up --
    // must not go out signed as a campaign.
    expect(senderNameOnBuiltMessage())->toBe($platformName);
});

test('a campaign password-reset mail is signed by that campaign', function (): void {
    $harbor = Tenant::query()->where('slug', 'harbor-cleanup')->firstOrFail();

    tenancy()->initialize($harbor);

    $operator = User::factory()->create(['email' => 'organizer@harbor-cleanup.test']);

    $operator->notify(new ResetPassword(Password::broker()->createToken($operator)));

    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    /** @var Email $message */
    $message = collect($transport->messages())->last()->getOriginalMessage();

    // The message this bootstrapper exists for, asserted end to end rather than
    // through a probe. The two halves are the point: the link host was already
    // campaign-correct before this change and the envelope was not, so a
    // campaign's operator got `harbor-cleanup.test` inside a message signed by
    // the platform. Asserting them together is what says the message now agrees
    // with itself about who is writing.
    expect($message->getFrom()[0]->getName())->toBe('Harbor Cleanup')
        ->and($message->getBody()->toString())->toContain('harbor-cleanup.test');
});

test('the sender-name bootstrapper is registered, so the guarantees above are wired', function (): void {
    // The configuration invariant behind the behavioural tests (L-14). Each of
    // those exercises the container, and container behaviour can look right by
    // accident of ordering; this cannot. Dropping the class from
    // config/tenancy.php turns this red on its own and turns the others red for
    // their own stated reasons.
    expect(config('tenancy.bootstrappers'))
        ->toContain(CampaignMailFromTenancyBootstrapper::class);
});
