<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Makes a campaign answer to the one address a browser test can ask for.
 *
 * The browser plugin's HTTP server binds to a hardcoded 127.0.0.1 and rewrites
 * every visit onto it, with no injection point -- so a campaign page is
 * unreachable until 127.0.0.1 *is* the campaign. Two arrangements do that: a
 * domain row pointing the address at this campaign, and central letting go of
 * the address for the length of the test.
 *
 * **Why this is a class rather than four lines in each file.** Domain rows are
 * central and outlive the campaign transaction, while the campaign harness
 * provisions a *different* campaign per test file -- so the second browser file
 * to claim the address is refused outright with "The 127.0.0.1 domain is
 * occupied by another tenant". That is not hypothetical: it is what happened
 * the first time a second browser file existed, and it took out a passing
 * Phase 1 test as well as the new one. Claiming the address therefore has to
 * begin by releasing whoever holds it.
 */
final class LoopbackHost
{
    /**
     * The address the browser can be made to ask for, and the only one.
     */
    public const string ADDRESS = '127.0.0.1';

    /**
     * Point the address at this campaign, taking it from whoever held it.
     *
     * The campaign's own `<slug>.test` row is left alone, so campaignUrl() and
     * anything else reading the campaign's hostname keep working.
     */
    public static function claimFor(Tenant $campaign): void
    {
        self::release();

        $campaign->createDomain(['domain' => self::ADDRESS]);

        // PreventAccessFromCentralDomains runs ahead of tenant resolution and
        // redirects any central host away from campaign routes, so while this
        // address is in that list the browser is sent to /campaign-sign-in no
        // matter what it asks for.
        //
        // Config only, for the life of the test. The committed default in
        // config/tenancy.php is untouched, which matters: that list is a
        // security boundary and this must not be a way of quietly widening it.
        config([
            'tenancy.central_domains' => array_values(array_diff(
                (array) config('tenancy.central_domains'),
                [self::ADDRESS],
            )),
        ]);
    }

    /**
     * Let go of the address, so the next test file can claim it.
     */
    public static function release(): void
    {
        Domain::query()->where('domain', self::ADDRESS)->delete();
    }
}
