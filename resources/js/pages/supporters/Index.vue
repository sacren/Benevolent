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
import type {
    DistrictClaim,
    Paginated,
    Segment,
    Supporter,
    SupporterDistricts,
} from '@/types';

const { segments, narrowedTo, districts } = defineProps<{
    supporters: Paginated<Supporter>;
    segments: Segment[];
    narrowedTo: number | null;
    districts: SupporterDistricts;
}>();

/**
 * What the server decided about this supporter's district.
 */
function claimFor(supporter: Supporter): DistrictClaim | undefined {
    return districts.bySupporter[supporter.id];
}

/**
 * Why no district is named, for each of the four reasons there can be.
 *
 * Four sentences rather than one "unknown", because each asks something
 * different of whoever reads it: a split ZIP code is nobody's mistake, a ZIP
 * code with no district data is a real ZIP code the Census gives no area, and
 * the last two are records somebody can correct. A page saying "unknown" for all
 * four teaches an operator to stop reading the column.
 *
 * A split ZIP code's districts are listed as what the supporter might be in and
 * never shown as the answer: that is `claimed`, and only the server sets it.
 * When the campaign has a seat, a split ZIP code touching it "may be in" it and
 * one touching none of it is "not in" it — the server decides which, and a
 * split ZIP code is never "in" the seat.
 */
function whyNoDistrict(claim: DistrictClaim): string {
    switch (claim.answer) {
        case 'split':
            if (claim.seatStanding === 'maybe') {
                return `May be in ${districts.seat}: this ZIP code crosses ${claim.touching.join(', ')}`;
            }

            if (claim.seatStanding === 'not') {
                return `Not in ${districts.seat}: this ZIP code crosses ${claim.touching.join(', ')}`;
            }

            return `Not named: this ZIP code crosses ${claim.touching.join(', ')}`;
        case 'unmapped':
            return 'Not named: no district data for this ZIP code';
        case 'malformed':
            return claim.mayHaveLostLeadingZero
                ? 'Not named: not a 5-digit ZIP code — a spreadsheet may have dropped its leading zero'
                : 'Not named: not a 5-digit ZIP code';
        case 'missing':
            return 'No ZIP code recorded';
        default:
            return 'Not named';
    }
}

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

        <!--
            What a narrowing to a district leaves out, said above the rows
            rather than left to be inferred from them (D-37). The segment reaches
            a supporter only when their ZIP code lies wholly inside the district,
            which leaves out everyone in a ZIP code crossing its boundary — in a
            dense district more ZIP codes than it keeps — so a heading naming
            the district would otherwise read as everyone in it. Shown whether or
            not anybody matched, because it is also the reason nobody might.
        -->
        <p
            v-if="narrowing && districts.narrowing"
            class="text-sm text-muted-foreground"
            data-test="district-narrowing"
        >
            <template v-if="districts.narrowing.seat">
                {{ narrowing.name }} narrows to
                {{ districts.narrowing.seat }} as the
                {{ districts.congress }} Congress drew it: supporters whose ZIP
                code lies wholly inside it, which
                {{ districts.narrowing.wholly }}
                {{
                    districts.narrowing.wholly === 1
                        ? 'ZIP code does'
                        : 'ZIP codes do'
                }}. Supporters in the {{ districts.narrowing.crossing }}
                {{
                    districts.narrowing.crossing === 1
                        ? 'ZIP code'
                        : 'ZIP codes'
                }}
                crossing its boundary are not shown, because their ZIP code
                cannot say which side of it they live on.
            </template>
            <template v-else>
                {{ narrowing.name }} names {{ districts.narrowing.district }},
                which is not a district in the {{ districts.congress }}
                Congress’s map, so it narrows to nobody.
            </template>
        </p>

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
                        <!--
                            The Congress is in the header rather than beside
                            every row because it is the same for every row, and
                            it has to be on the screen wherever an answer is:
                            a district named without its Congress is a claim
                            about boundaries that may no longer be on the ballot
                            (D-43).
                        -->
                        <th scope="col" class="px-4 py-3 font-medium">
                            District
                            <span class="normal-case"
                                >({{ districts.congress }} Congress)</span
                            >
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
                        <!--
                            The district shown is `claimed` and nothing else,
                            and the data-test marks it so that a browser can say
                            it is absent from a row whose ZIP code crosses a
                            boundary. Reading a district out of `touching`
                            instead would put a split ZIP code's first district
                            on the screen as the supporter's own, and every
                            server-side assertion would still pass.
                        -->
                        <td class="px-4 py-3">
                            <template v-if="claimFor(supporter)?.claimed">
                                <span
                                    :data-test="`district-claimed-${supporter.id}`"
                                    >{{ claimFor(supporter)?.claimed }}</span
                                >
                                <!--
                                    "Your seat" only when the server says
                                    `in`. A check that the standing merely
                                    exists would put it beside every row the
                                    seat was compared with, including ones the
                                    server said are not in it.
                                -->
                                <span
                                    v-if="
                                        claimFor(supporter)?.seatStanding ===
                                        'in'
                                    "
                                    class="text-muted-foreground"
                                    :data-test="`district-in-seat-${supporter.id}`"
                                >
                                    · your seat</span
                                >
                                <span
                                    v-else-if="
                                        claimFor(supporter)?.seatStanding ===
                                        'not'
                                    "
                                    class="text-muted-foreground"
                                >
                                    · not your seat</span
                                >
                            </template>
                            <span
                                v-else-if="claimFor(supporter)"
                                class="text-muted-foreground"
                                :data-test="`district-not-named-${supporter.id}`"
                                >{{
                                    whyNoDistrict(
                                        claimFor(supporter) as DistrictClaim,
                                    )
                                }}</span
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
            Which map every answer above was read against, said once beneath
            the table. The product's district data is the Census Bureau's, for
            the Congress it names, and states that redrew their maps after it was
            published are not in it — which is exactly why the Congress is named
            rather than implied (D-43). No list of redrawn states is kept: one
            typed from news reports would be wrong in both directions as courts
            ruled, and this sentence is true whatever they decide.
        -->
        <p
            v-if="supporters.total > 0"
            class="text-sm text-muted-foreground"
            data-test="district-map"
        >
            Districts are those of the {{ districts.congress }} Congress, from
            the Census Bureau’s ZIP code data published
            {{ districts.publishedOn }}. States that have redrawn their maps
            since then are not reflected. A district is named only for a ZIP
            code that lies wholly inside one.
            <template v-if="districts.seat">
                This campaign is running for {{ districts.seat }}, and “your
                seat” means {{ districts.seat }} as the
                {{ districts.congress }} Congress drew it.
            </template>
        </p>

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
