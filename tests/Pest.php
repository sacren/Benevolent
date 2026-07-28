<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\BuiltAssets;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Tenancy tests provision real per-tenant PostgreSQL databases. CREATE DATABASE
// cannot run inside a transaction, so these tests can't use transactional
// RefreshDatabase — they manage the central schema and tenant DBs explicitly.
pest()->extend(TestCase::class)
    ->in('Tenancy');

// Feature tests served in campaign context. Operators exist only in a campaign's
// own database, so anything exercising authentication — or any other route served
// to a campaign — belongs here rather than in tests/Feature, whose tests run
// centrally. The trait provisions one campaign per file and wraps each test in a
// transaction on its connection.
//
// Note that a directory can only be configured once, so campaign tests cannot be
// nested inside tests/Tenancy while that folder is configured above.
pest()->extend(TestCase::class)
    ->use(RunsInCampaignContext::class)
    ->beforeEach(function (): void {
        $this->enterCampaignContext();
    })
    ->afterEach(function (): void {
        $this->leaveCampaignContext();
    })
    ->in('Campaign');

// Tests driven through a real browser. Everything they assert depends on Vue
// having mounted, so every one of them first pins the front end to the build
// output rather than to a Vite dev server -- see Tests\Support\BuiltAssets for
// why that is a per-test binding rather than a file being moved out of the way.
//
// Done here rather than in each file so that it cannot be forgotten: a browser
// test written without it does not fail, it passes against an empty page.
//
// Campaign context is deliberately *not* applied to the whole directory. One of
// these pages is central and anonymous by design, so a file needing a campaign
// opts in for itself.
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        BuiltAssets::serveFromBuild();
    })
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Run a write the database is expected to refuse, and hand back its complaint.
 *
 * A statement PostgreSQL refuses aborts the transaction it was made in, and the
 * campaign suite wraps every test body in one so that nothing a test writes
 * survives it. Running the refusal inside a nested transaction turns it into a
 * savepoint, so the refusal is rolled back to and the surrounding transaction —
 * along with every assertion after this call, and the harness's own rollback —
 * stays usable.
 *
 * Declared here rather than in the file that first needed it because a global
 * function cannot be declared twice: it was written inside
 * tests/Campaign/SupporterStorageTest.php for D-8's unique index, and
 * tests/Campaign/BlastStorageTest.php now needs the same thing for the check
 * constraint that keeps a draft and a queued blast apart. A second consumer is
 * this project's trigger for lifting a helper, and the alternative — a
 * differently named copy — would leave two spellings of one idea.
 *
 * Returns null when the write was *not* refused, which is what lets a caller
 * assert that a refusal happened rather than assert on a value that may never
 * have been set.
 */
function refusalFrom(Closure $write): ?QueryException
{
    try {
        DB::connection('tenant')->transaction($write);
    } catch (QueryException $refusal) {
        return $refusal;
    }

    return null;
}
