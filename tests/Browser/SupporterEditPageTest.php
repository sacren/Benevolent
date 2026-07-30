<?php

declare(strict_types=1);

use App\Models\Supporter;
use App\Models\User;
use Tests\Concerns\RunsInCampaignContext;
use Tests\Support\LoopbackHost;

/*
 * The supporter edit form, opened in a real browser by a signed-in Owner.
 *
 * **This file exists because the page it opens was broken and nothing could
 * tell.** defineOptions() is hoisted out of <script setup>, so a breadcrumb
 * built from `supporter.id` threw `ReferenceError: supporter is not defined`,
 * Vue never mounted, and the page rendered nothing at all -- while the route
 * answered 200 with the right component name and every server-side assertion
 * about it stayed green. It shipped in Phase 1 and was found here, by writing
 * the same idiom on a new page and opening that one in a browser.
 *
 * So the guard is not "this page renders". It is that **a page whose layout
 * depends on its own props still mounts**, which is the one class of defect
 * L-12 names and the reason this suite was built: no server-side assertion can
 * distinguish a page that rendered from a page that threw before mounting.
 *
 * Kept minimal on purpose. The supporter form's behaviour is covered by
 * tests/Campaign/SupporterManagementTest.php, and duplicating that here would
 * pay a browser's cost for assertions the server already makes.
 */

uses(RunsInCampaignContext::class);

beforeEach(function (): void {
    $this->enterCampaignContext();

    LoopbackHost::claimFor($this->campaign);
});

afterEach(function (): void {
    LoopbackHost::release();

    $this->leaveCampaignContext();
});

test('the edit form mounts, with the supporter in the fields and in the breadcrumb', function (): void {
    $supporter = Supporter::factory()->create([
        'name' => 'Ama Boateng',
        'email' => 'ama.boateng@example.test',
        'postcode' => 'M15 6BH',
    ]);

    $this->actingAs(User::factory()->owner()->create());

    $page = visit('/supporters/'.$supporter->getKey().'/edit');

    $page
        // The heading is the supporter's own name, so seeing it proves both
        // that Vue mounted and that the server's prop reached the template.
        ->assertSee('Ama Boateng')

        // The breadcrumb is the half that was throwing. It is built from the
        // page's own props, which is exactly what defineOptions() cannot see,
        // so this is the assertion that would have caught the defect.
        ->assertSee('Edit supporter')

        ->assertPresent('[data-test="sidebar-menu-button"]')

        // The claim that actually failed. Every assertion above is downstream
        // of Vue mounting, so this is what names the cause rather than a
        // symptom.
        ->assertNoJavaScriptErrors();

    // And the field values, which are properties rather than page text and so
    // appear in no markup assertion the server or the DOM text could make.
    $page->assertScript('document.getElementById("email").value === "ama.boateng@example.test"');
    $page->assertScript('document.getElementById("postcode").value === "M15 6BH"');
});
