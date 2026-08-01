<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import UnsubscribeController from '@/actions/App/Http/Controllers/UnsubscribeController';
import { Button } from '@/components/ui/button';

/*
 * The page a supporter lands on from the link in a message.
 *
 * **It declares no layout at all, and that is the whole of L-12 answered here.**
 * `resources/js/app.ts` picks a layout by page name in a `switch (true)` whose
 * default arm is AppLayout -- the signed-in application shell, with a sidebar,
 * a campaign navigation and an account menu that reads `$page.props.auth.user`.
 * A page named anything the resolver does not know falls into that arm and
 * renders that shell to a stranger who by definition has no session, **while
 * the route still answers 200 with the right component name**, so `assertInertia`
 * is perfectly satisfied. The name is registered in the resolver's `null` arm in
 * the same edit as this file, and a browser test is what proves it.
 *
 * **`null` rather than AuthLayout, which was the other precedented answer.**
 * AuthLayout reads no `auth.user` and would not break -- but AuthSimpleLayout
 * renders the platform's own logo and links to the platform's home page, and
 * this is a campaign's public surface shown to one of its supporters. The
 * platform is not a party to that. It is the same reasoning that kept
 * `config('app.name')` out of the message itself (deferral 21): a campaign's
 * advocacy mail must not arrive headed by the platform, and the page that mail
 * points at must not either.
 *
 * There is no `defineOptions({ layout: ... })` here for that reason, so the
 * hoisting hazard that broke the supporter edit page at Step 3 -- where
 * defineOptions could not see props and threw a ReferenceError while the route
 * still answered 200 -- cannot arise.
 */
defineProps<{
    token: string;
    email: string;
    campaignName: string;
    unsubscribed: boolean;
}>();
</script>

<template>
    <Head title="Unsubscribe" />

    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 text-foreground md:p-10"
    >
        <div class="w-full max-w-md space-y-6">
            <!--
                Whose list this is, said first. A stranger arriving from an
                inbox may not recognise the campaign by its hostname, and the
                page carries none of the platform's branding to fall back on.
            -->
            <div class="space-y-2 text-center">
                <h1 class="text-xl font-medium">{{ campaignName }}</h1>
                <p class="text-sm text-muted-foreground">
                    Email preferences for
                    <span class="font-medium text-foreground">{{ email }}</span>
                </p>
            </div>

            <!--
                Already done, which is what a second visit months later sees.
                The page's content is a function of the supporter's current
                status rather than of which request produced it, so the POST
                redirects back here and a refresh is safe.
            -->
            <div
                v-if="unsubscribed"
                data-test="unsubscribe-done"
                class="space-y-3 rounded-xl border border-sidebar-border/70 p-6 text-center dark:border-sidebar-border"
            >
                <p class="text-sm font-medium">You have been unsubscribed.</p>
                <p class="text-sm text-muted-foreground">
                    {{ campaignName }} will not send you any more email. You can
                    close this page.
                </p>
                <!--
                    No way back from here, deliberately (D-21). A link that
                    could re-subscribe would turn a forwarded or leaked message
                    into a way of putting an address back onto a list it asked
                    to leave. Somebody who clicked by mistake asks the campaign.
                -->
                <p class="text-sm text-muted-foreground">
                    If you did this by mistake, reply to one of their earlier
                    messages and ask to be added back.
                </p>
            </div>

            <!--
                Not done yet: a confirmation, and the act is a POST.

                A GET that mutates is what every other part of this codebase
                refuses, and here it would be a live defect rather than an
                inelegance -- mail providers' link scanners and client
                prefetchers issue GETs against every URL in a message, so a
                mutating GET unsubscribes people from mail they wanted, on
                delivery, with nobody having clicked anything.
            -->
            <div
                v-else
                data-test="unsubscribe-confirm"
                class="space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
            >
                <p class="text-sm">
                    Stop receiving email from {{ campaignName }}?
                </p>
                <p class="text-sm text-muted-foreground">
                    You will not need an account and nothing else changes. This
                    only stops the messages.
                </p>

                <Form
                    v-bind="UnsubscribeController.store.form(token)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="destructive"
                        class="w-full"
                        :disabled="processing"
                        data-test="unsubscribe-button"
                    >
                        Unsubscribe
                    </Button>
                </Form>
            </div>
        </div>
    </div>
</template>
