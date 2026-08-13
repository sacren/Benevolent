<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SupporterController from '@/actions/App/Http/Controllers/SupporterController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { create, edit, exportMethod, index } from '@/routes/supporters';
import { create as importList } from '@/routes/supporters/imports';
import type { Paginated, Segment, Supporter } from '@/types';

const { segments, narrowedTo } = defineProps<{
    supporters: Paginated<Supporter>;
    segments: Segment[];
    narrowedTo: number | null;
}>();

const { can } = usePermissions();

/**
 * The segment the list is currently narrowed to, if any.
 *
 * Resolved from the id the server sent rather than kept as page state, so the
 * heading and the export control describe the same request that produced the
 * rows. A narrowing held in the page instead would be a second opinion, free to
 * disagree with what was actually queried after a back button or a page link.
 */
const narrowing = computed(
    () => segments.find((segment) => segment.id === narrowedTo) ?? null,
);

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

                And the heading says what was actually asked for, because the
                count alone is ambiguous once the list can be narrowed: 12
                people out of 12,000 and 12 people in total read identically,
                and only one of them is a reason to go looking for the rest.
            -->
            <Heading
                title="Supporters"
                :description="
                    narrowing
                        ? `${supporters.total} ${supporters.total === 1 ? 'person' : 'people'} in ${narrowing.name}`
                        : `${supporters.total} ${supporters.total === 1 ? 'person' : 'people'} on this campaign’s list`
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

                    It carries the narrowing, so the file holds what the screen
                    holds, and the label says which of the two it will be rather
                    than leaving an operator to find out by opening it.
                -->
                <Button
                    v-if="can('export-supporters')"
                    as-child
                    variant="outline"
                >
                    <a
                        :href="
                            exportMethod.url(
                                narrowing
                                    ? { query: { segment: narrowing.id } }
                                    : undefined,
                            )
                        "
                        data-test="export-supporters"
                        >{{
                            narrowing
                                ? `Export ${narrowing.name}`
                                : 'Export the list'
                        }}</a
                    >
                </Button>

                <Button as-child variant="outline">
                    <Link :href="importList()">Import a list</Link>
                </Button>

                <Button as-child>
                    <Link :href="create()">Add supporter</Link>
                </Button>
            </div>
        </div>

        <!--
            Aiming the list, and it is a client-side visit where the Export
            control beside it is a plain navigation. Both render as ordinary
            controls and the difference is invisible in the markup: this route
            answers with a JSON page object and must be visited, that one
            answers with a file and must not be. Getting either backwards leaves
            a control that looks right and does the wrong thing, which is the
            defect class only a browser can report.

            Submitted rather than applied on change, so an operator using a
            keyboard is not navigated away mid-selection. Method GET, so the
            narrowing lands in the query string where `withQueryString()` can
            carry it onto every page link.

            Rendered only when the campaign has named a segment: a select with
            nothing in it but "the whole list" is a control nobody can aim, which
            is the same argument the paging controls below make for themselves.
        -->
        <Form
            v-if="segments.length > 0"
            v-bind="SupporterController.index.form()"
            class="flex flex-wrap items-end gap-3"
            v-slot="{ processing }"
        >
            <div class="grid gap-2">
                <Label for="segment">Narrow to a segment</Label>
                <select
                    id="segment"
                    name="segment"
                    data-test="narrow-to-segment"
                    class="h-9 w-64 min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                >
                    <option value="">Everyone on the list</option>
                    <option
                        v-for="segment in segments"
                        :key="segment.id"
                        :value="segment.id"
                        :selected="segment.id === narrowedTo"
                    >
                        {{ segment.name }}
                    </option>
                </select>
            </div>

            <Button type="submit" variant="outline" :disabled="processing"
                >Apply</Button
            >
        </Form>

        <div
            v-if="supporters.total === 0"
            class="rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
        >
            <!--
                A narrowed list with nobody in it is a different statement from
                a campaign with nobody on its list, and telling an operator to
                import when they have 12,000 supporters and a segment matching
                none of them would be answering a question they did not ask.
            -->
            <p v-if="narrowing" class="text-sm text-muted-foreground">
                Nobody on this campaign’s list is in
                {{ narrowing.name }}.
            </p>
            <p v-else class="text-sm text-muted-foreground">
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
                            ZIP code
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
            numbers ready-made in `links`, and they are still left unused.

            **The trigger this comment used to carry has fired and the remedy it
            named is refused, which is a different outcome from the trigger
            being wrong.** It read: "with no search on this page yet, a page
            number tells an operator nothing about who is on it… the trigger to
            add them is the same one that makes them meaningful — a way to
            search or filter the list." This step is that filter, so the
            condition arrived on schedule.

            The premise did not move with it. Page 3 of a segment-narrowed list
            still tells an operator nothing about who is on it, because the
            ordering is still arrival-descending and a narrowing changes the
            *set* rather than the order. What makes a page number aimable is an
            ordering somebody can reason about, not a smaller set — and a filter
            makes the list shorter, which is a reason to want numbers *less*.
            The trigger counted filters and the value lives in the ordering:
            different nouns, which is the tell Blueprint v0.28 records and the
            second time this codebase has produced it, after the blast list's
            own trigger counted rows on the page while the cost sat in another
            table.

            **Trigger to revisit, replacing the one above:** the first ordering
            an operator chooses — a sort by name is the obvious first — because
            that is what makes a page number predict who is on it. Whether the
            schema can offer that honestly is a separate question this module
            has already answered once: a supporter whose source gave one name
            string has no family name to sort on.

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
