<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant as Campaign;

/**
 * The address a campaign's mail comes back to.
 *
 * **This is the half of "what a campaign sends as" that costs nothing (D-15).**
 * The sender *name* is already the campaign's, applied by
 * CampaignMailFromTenancyBootstrapper. The sender *address* is the platform's
 * and stays that way, because sending as `hello@harbor-cleanup.test` needs SPF
 * and DKIM alignment, a verified domain and a bounce path that comes back
 * somewhere real -- a credential and deliverability question rather than a
 * configuration one. A `Reply-To` needs none of those. It is free text carried
 * in a header, and it is the whole difference between a message a supporter can
 * answer and one they cannot.
 *
 * That difference is not cosmetic for this product. A password reset is a
 * message nobody replies to; an advocacy message is one where a supporter's
 * reply is the point, and a campaign that mails ten thousand people from an
 * address that discards their answers has done something worse than not writing.
 *
 * **Stored on the campaign's registry row, and this is that substrate's first
 * real consumer.** Phase 0 Step 14 measured that a value assigned to a campaign
 * folds into `tenants.data` through VirtualColumn with no migration, and that
 * reading it inside campaign context costs *no query at all*, because the
 * campaign's own record is the object tenancy is already holding. It then
 * shipped no vocabulary, deliberately, because a foundation with no modules has
 * nothing to name. This is the first thing to name.
 *
 * Note what that buys beyond convenience. With no cache anywhere in the read
 * path, the `__call` bypass this project has measured four separate times --
 * a permission package at `store()`, the rate limiter at `driver()`, cache
 * locks at the container alias, and the mail manager's own cached mailers --
 * cannot arise here at all. The thinnest mechanism is also the safest one.
 *
 * **Optional, and that is a decision rather than an omission.** A campaign with
 * no contact address sends with no reply path, and the surface that offers to
 * send says so. Requiring one before a campaign may send would make it a
 * per-campaign prerequisite for sending -- which is the exact thing D-13 found
 * this repository does not have, and on whose absence it resolved that the
 * blast module is not feature-enablable. A required address would reopen a
 * decision that is closed, through a door nobody would think to look at.
 */
final class CampaignContact
{
    /**
     * The key this value is stored under on the campaign's registry row.
     *
     * Named once here rather than spelled at each call site, because a
     * mistyped key does not fail: `tenant('contatc_address')` reads null, which
     * is indistinguishable from a campaign that has not set one, and the blast
     * goes out with no reply path while everything reports success.
     *
     * Deliberately *not* `enabled_modules`, which
     * tests/Tenancy/CampaignSettingsStorageTest.php reserves as its own
     * test-only key and states that nothing in the application reads.
     */
    public const string KEY = 'contact_address';

    /**
     * The current campaign's contact address, or null if it has none.
     *
     * Returns null outside campaign context rather than falling back to
     * anything, which is the property Phase 0 Step 14 pinned and the reason
     * that step declined the tenancy package's own config-override feature
     * (L-29): a campaign's setting reached through `config()` answers with the
     * *platform default* wherever no campaign is active, and a console command
     * and a worker between jobs are both such a place. A question about one
     * campaign getting a plausible answer where the honest answer is "there is
     * no campaign to ask about" is the failure this shape avoids.
     */
    public static function address(): ?string
    {
        $stored = tenant(self::KEY);

        if (! is_string($stored)) {
            return null;
        }

        $address = trim($stored);

        return $address === '' ? null : $address;
    }

    /**
     * Record the address a campaign's mail should come back to.
     *
     * Takes the campaign rather than reading the active one, because every
     * caller is outside campaign context: a provisioning command and a
     * correction command both address a campaign by name from central.
     */
    public static function store(Campaign $campaign, string $address): void
    {
        $campaign->setAttribute(self::KEY, trim($address));
        $campaign->save();
    }

    /**
     * Whether a string is usable as a reply address.
     *
     * The same test the importer applies to a supporter's address, for the same
     * reason it is applied there rather than trusted: this value is typed by a
     * person at a terminal, it is never seen again by whoever typed it, and a
     * malformed one fails at the far end of a send -- in a header, on a message
     * already delivered, where nobody is watching.
     */
    public static function usable(string $address): bool
    {
        return filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false;
    }
}
