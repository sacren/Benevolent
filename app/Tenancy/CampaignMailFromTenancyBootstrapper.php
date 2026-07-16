<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant as Campaign;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Signs a campaign's mail with the campaign's own name.
 *
 * Everything *host*-shaped is already re-derived per campaign by
 * CampaignHostTenancyBootstrapper, and nothing was re-deriving anything
 * *identity*-shaped, so a campaign's mail arrived half right: an operator of a
 * campaign called Harbor Cleanup received a password reset from
 * `Benevolent <hello@example.com>` carrying a link to `harbor-cleanup.test`.
 * Correct and incorrect in the same message, from one value nobody had claimed.
 *
 * **Only the name, deliberately.** The sender name and the sender address are
 * not two halves of one setting. A name is free text and costs nothing to
 * change. An address has to be one the platform is authorized to send as --
 * SPF and DKIM alignment, a verified domain, and a bounce path that comes back
 * somewhere real -- so sending as `hello@harbor-cleanup.test` is a credential
 * and deliverability question rather than a configuration one, and it needs a
 * per-campaign secret that no campaign has yet. That half is deferred with the
 * secret storage it depends on; this half is owed today and needs neither.
 *
 * `config/mail.php`'s SMTP `local_domain` is deliberately untouched. It is the
 * EHLO name identifying the sending *platform* to the SMTP server rather than
 * the campaign, and it resolves through `env()` at config load where no
 * bootstrapper could reach it in any case.
 *
 * **The letterhead inside the message is still the platform's, and that is
 * deliberate rather than overlooked.** The framework's notification templates
 * print `config('app.name')` as the mail header, the HTML title and the footer
 * copyright, so a campaign's reset mail arrives *from* the campaign and is
 * still headed by the platform. Steering `app.name` here would fix that in one
 * line and would also rename the browser title on every campaign page and the
 * name rendered by the split auth layout, because those read the same key --
 * which is campaign branding, a per-product variation point belonging to a
 * later phase, not a security baseline. Verified while measuring it that the
 * blast radius stops there: the cache prefix, Redis prefix, session cookie and
 * log channel name all read APP_NAME from the environment at config load, so
 * none of them would follow a runtime change. Deferred with that note, so
 * whoever picks up campaign branding finds the two options already costed.
 *
 * **Forgetting the resolved mailers is not optional.** MailManager caches every
 * mailer it builds, and it applies the global from address once, at build time,
 * inside `resolve()`. Setting the config without clearing that cache changes
 * nothing at all: the second campaign in a process is handed the first
 * campaign's mailer, still signed with the first campaign's name -- and it
 * reports success while doing it, which is the shape L-21 was written about.
 * `forgetMailers()` is used rather than dropping the container binding because
 * it clears exactly the cached mailers while leaving any custom transport
 * registered through `Mail::extend()` in place; building a fresh manager costs
 * nothing, since transports are only constructed when a mailer is resolved.
 */
class CampaignMailFromTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * The platform's own sender name, captured before any campaign replaces it.
     */
    private ?string $platformSenderName = null;

    public function __construct(private readonly Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        if (! $tenant instanceof Campaign) {
            return;
        }

        $name = trim((string) $tenant->name);

        if ($name === '') {
            // A campaign without a name should sign as the platform rather than
            // as nobody. Not reachable through campaign:create, which requires
            // one, but an unnamed sender is a worse answer than a generic one.
            return;
        }

        // Captured once, and only ever while the platform's own value is still
        // in place. The sibling host bootstrapper recomputes its central values
        // on revert instead of remembering them, which is the better pattern
        // and is not available here: `mail.from.name` has no untouched twin to
        // read back from -- config/fortify.php still holds the central passkey
        // values, whereas config/mail.php holds the very key being replaced --
        // and reading MAIL_FROM_NAME from the environment directly would return
        // null wherever the config is cached.
        //
        // The `??=` is what makes remembering safe. Two bootstraps without an
        // intervening revert cannot capture the first campaign's name as though
        // it were the platform's, which is the hazard the host bootstrapper
        // avoids by not remembering at all.
        $this->platformSenderName ??= (string) config('mail.from.name');

        config(['mail.from.name' => $name]);

        $this->forgetResolvedMailers();
    }

    public function revert(): void
    {
        // Both hooks are load-bearing, and asymmetrically so. Entering a
        // campaign from central passes through bootstrap() only; leaving one
        // passes through revert() only; switching between two campaigns passes
        // through both, because the tenancy package ends tenancy before
        // initializing a different campaign. Without this half, a central
        // caller after a campaign request -- a console command, the next job in
        // a worker -- would send platform mail signed with a campaign's name.
        if ($this->platformSenderName !== null) {
            config(['mail.from.name' => $this->platformSenderName]);

            $this->platformSenderName = null;
        }

        $this->forgetResolvedMailers();
    }

    /**
     * Drop the cached mailers so the next one built reads the current name.
     */
    private function forgetResolvedMailers(): void
    {
        $this->app->make(MailManager::class)->forgetMailers();
    }
}
