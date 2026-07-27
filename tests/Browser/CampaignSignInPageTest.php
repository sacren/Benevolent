<?php

declare(strict_types=1);

/*
 * The central signpost page, opened in a real browser.
 *
 * This is the test the frontend verification hole was named for. L-12 is a
 * *rendering* defect and nothing on the server can see it: `resources/js/app.ts`
 * picks a layout by page name in a `switch (true)` whose default arm is
 * AppLayout -- the signed-in application shell. A page that is neither the app
 * proper nor an auth page falls into that arm and renders inside a shell built
 * for someone who is signed in, on a host where by design nobody can be. The
 * route still answers 200, the component name is still right, so
 * `assertInertia(...)->component('CampaignSignIn')` passes throughout. Only
 * opening the page can tell.
 *
 * Central and anonymous, so this file needs no campaign and no operator. That
 * is not a convenience: `127.0.0.1` is unconditionally a central domain in
 * config/tenancy.php, and the browser's HTTP server binds there and rewrites
 * every visit onto it, so this page is the one the browser can reach with no
 * arrangement at all.
 */

test('the central signpost renders outside the application shell', function (): void {
    $page = visit('/campaign-sign-in');

    $page
        // First, that Vue mounted at all. Every other assertion in this file is
        // evidence only because of this one -- an empty `<div id="app">` would
        // satisfy both of the "not the app shell" claims below perfectly, which
        // is exactly how the first attempt at this test would have been green
        // for nothing.
        ->assertSee('Campaign staff sign in on their own campaign')

        // The positive half of the L-12 claim, and it turns on *where* this
        // text can come from. Both strings are the `title` and `description`
        // layout props this page declares, and only AuthLayout renders them --
        // AppLayout's props are breadcrumbs and it would drop both on the
        // floor. So their presence in the body is the auth shell's signature,
        // not merely the page's own content.
        ->assertSee('Sign in on your campaign')
        ->assertSee('Every campaign has its own web address')

        // And the negative half. The application shell's user menu is the part
        // that cannot survive here -- it reads `auth.user`, which is null on a
        // central page -- so its presence is the defect's signature.
        //
        // This assertion is only worth anything because SupporterListPageTest
        // asserts the *same selector is present* on a page that does wear the
        // app shell. That pairing is deliberate: an absence assertion proves
        // nothing on its own, and this plugin makes that trap unusually easy to
        // fall into -- a selector it does not recognise as CSS silently becomes
        // a text search, so `assertMissing('aside')` would pass on every page
        // ever written. Explicit selectors only, here and everywhere.
        ->assertMissing('[data-test="sidebar-menu-button"]')

        // The sidebar's campaign navigation, absent for the same reason and
        // asserted separately because it fails one step earlier: the nav items
        // render before anything reads the operator, so a shell that renders
        // partially still shows these.
        ->assertDontSee('Supporters')

        ->assertNoJavaScriptErrors();
});
