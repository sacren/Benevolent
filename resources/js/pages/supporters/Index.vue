<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SupporterController from '@/actions/App/Http/Controllers/SupporterController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import { create, edit, exportMethod, index } from '@/routes/supporters';
import { create as importList } from '@/routes/supporters/imports';
import type { Paginated, Supporter } from '@/types';

defineProps<{
    supporters: Paginated<Supporter>;
}>();

const { can } = usePermissions();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Supporters',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Supporters" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <!--
                `total` rather than `data.length`, and this is the trap a
                paginated page sets rather than a preference. `data` is the 50
                rows this page carries, so counting it would tell an operator
                with 4,000 supporters that they have 50 — a wrong answer that
                looks entirely plausible, and one that only stops being wrong on
                the last page.
            -->
            <Heading
                title="Supporters"
                :description="
                    supporters.total === 1
                        ? '1 person on this campaign’s list'
                        : `${supporters.total} people on this campaign’s list`
                "
            />

            <div class="flex items-center gap-3">
                <!--
                    A plain anchor, not a <Link>, and not by oversight: an
                    Inertia visit is an XHR expecting a JSON page object, and
                    this route answers with a file. It has to be an ordinary
                    navigation for the browser to hand it to the operator.

                    Hidden from anyone who may not export, the same courtesy the
                    Remove control gets below and with the same standing: the
                    policy refuses the request regardless, so getting this wrong
                    costs a button or a 403, never the list.

                    `exportMethod` rather than `export`: Wayfinder renames the
                    generated helper because `export` is a reserved word in
                    JavaScript and cannot be a binding name. The route is still
                    `supporters.export`.
                -->
                <Button
                    v-if="can('export-supporters')"
                    as-child
                    variant="outline"
                >
                    <a :href="exportMethod.url()">Export the list</a>
                </Button>

                <Button as-child variant="outline">
                    <Link :href="importList()">Import a list</Link>
                </Button>

                <Button as-child>
                    <Link :href="create()">Add supporter</Link>
                </Button>
            </div>
        </div>

        <div
            v-if="supporters.total === 0"
            class="rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
        >
            <p class="text-sm text-muted-foreground">
                No supporters yet. Everyone this campaign adds or imports will
                appear here.
            </p>
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Email</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Postcode
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="supporter in supporters.data"
                        :key="supporter.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <!--
                            `name` is used as stored rather than recomposed from
                            the parts: it is already the display string the
                            source gave or the importer joined, and rebuilding it
                            here would be a second opinion free to disagree with
                            the first. A row with no name at all is ordinary
                            rather than broken, so it says so instead of leaving
                            a blank cell that reads as a rendering fault.
                        -->
                        <td class="px-4 py-3">
                            <span v-if="supporter.name">{{
                                supporter.name
                            }}</span>
                            <span v-else class="text-muted-foreground"
                                >No name recorded</span
                            >
                        </td>
                        <td class="px-4 py-3">{{ supporter.email }}</td>
                        <td class="px-4 py-3">
                            <span v-if="supporter.postcode">{{
                                supporter.postcode
                            }}</span>
                            <span v-else class="text-muted-foreground"
                                >&mdash;</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    supporter.subscription_status ===
                                    'subscribed'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    supporter.subscription_status ===
                                    'subscribed'
                                        ? 'Subscribed'
                                        : 'Unsubscribed'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div
                                class="flex items-center justify-end gap-3 text-sm"
                            >
                                <Link
                                    :href="edit(supporter.id)"
                                    class="underline underline-offset-4"
                                    >Edit</Link
                                >

                                <!--
                                    Hidden from an operator who may not remove
                                    anyone, and hidden by asking what they may
                                    *do* rather than what they are called. The
                                    policy refuses the request regardless, so
                                    this is a courtesy rather than the guard:
                                    getting it wrong costs a button or a 403,
                                    never access.
                                -->
                                <Form
                                    v-if="can('delete-supporters')"
                                    v-bind="
                                        SupporterController.destroy.form(
                                            supporter.id,
                                        )
                                    "
                                    v-slot="{ processing }"
                                    :options="{ preserveScroll: true }"
                                >
                                    <button
                                        type="submit"
                                        :disabled="processing"
                                        class="text-destructive underline underline-offset-4 disabled:opacity-50"
                                        :data-test="`remove-supporter-${supporter.id}`"
                                    >
                                        Remove
                                    </button>
                                </Form>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!--
            Inertia <Link>s, unlike the Export control above, and the contrast is
            the whole reason that one carries a comment. Paging is an ordinary
            visit that *should* be answered with a JSON page object; exporting is
            a file that must arrive as a real navigation. Same page, two kinds of
            link, for opposite reasons.

            Previous and Next rather than a numbered strip. Laravel offers the
            numbers ready-made in `links`, and they were left unused deliberately:
            with no search on this page yet, a page number tells an operator
            nothing about who is on it, so a strip of them is a row of controls
            nobody can aim. The trigger to add them is the same one that makes
            them meaningful — a way to search or filter the list.

            Rendered only when there is more than one page, so a campaign with
            nine supporters is not shown paging controls for a list that does not
            page.
        -->
        <nav
            v-if="supporters.last_page > 1"
            aria-label="Supporter list pages"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <p class="text-sm text-muted-foreground">
                Showing {{ supporters.from }}–{{ supporters.to }} of
                {{ supporters.total }}
            </p>

            <div class="flex items-center gap-3">
                <!--
                    `as-child` with a <Link> when there is somewhere to go, and a
                    disabled <button> when there is not. A <Link> with a null href
                    is still focusable and still navigates somewhere unhelpful, so
                    the two states are different elements rather than one element
                    with a class on it.
                -->
                <Button
                    v-if="supporters.prev_page_url"
                    as-child
                    variant="outline"
                >
                    <Link :href="supporters.prev_page_url" rel="prev"
                        >Previous</Link
                    >
                </Button>
                <Button v-else variant="outline" disabled>Previous</Button>

                <Button
                    v-if="supporters.next_page_url"
                    as-child
                    variant="outline"
                >
                    <Link :href="supporters.next_page_url" rel="next"
                        >Next</Link
                    >
                </Button>
                <Button v-else variant="outline" disabled>Next</Button>
            </div>
        </nav>
    </div>
</template>
