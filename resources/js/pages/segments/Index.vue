<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SegmentController from '@/actions/App/Http/Controllers/SegmentController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/segments';
import type { Segment, SegmentDistricts } from '@/types';

const { districts } = defineProps<{
    segments: Segment[];
    districts: SegmentDistricts | null;
}>();

/**
 * How a segment describes who it narrows to, in a list with no room for a
 * count.
 *
 * **Deliberately a summary of the rule and not a size, and D-28 resolved that
 * as the answer rather than as this page's convenience.** A number beside each
 * row is one query per row: against 250,000 supporters that is 270.4 ms at one
 * segment, 825.9 ms at three and 2,752.5 ms at ten, where this page as it
 * stands costs 1.4 ms — and the single-statement form anybody would reach for
 * instead is worse at every one of those sizes. The reason that settles it is
 * not the cost, though, because a cost expires and this does not: the supporter
 * list narrowed by one segment and a blast aimed at the same segment answer
 * 250,000 and 187,615, so one number here would misinform whichever of its two
 * readers it was not computed for, by the whole of the campaign's unsubscribed
 * rate. SegmentController::index() carries the measurements and the third shape
 * this refuses — a stored count, which nothing exists to refresh.
 *
 * There is no "everyone" branch here, unlike the blast list's version of this
 * function. A blast's rule may be null and null means the whole contactable
 * list; a segment names ZIP codes or a district and the database refuses one
 * naming neither, so a segment always names somewhere. An empty list is still
 * possible in the column and still means nobody, so it is said in words rather
 * than rendered as an empty cell that would read as a rendering fault.
 *
 * **A district is named with the Congress whose map it is read against, and
 * with what it leaves out (D-37, D-43).** "MA-07" alone would read as everyone
 * in MA-07; the segment reaches only ZIP codes lying wholly inside it. A
 * district the server's relation does not name reaches nobody, and says so.
 */
function ruleSummary(segment: Segment): string {
    if (segment.district !== null) {
        // The server sends `districts` whenever a district segment exists, so
        // null here is unreachable; it is answered the safe way regardless.
        if (districts === null) {
            return `Nobody: ${segment.district} could not be read as a district`;
        }

        if (districts.unnamed.includes(segment.id)) {
            return `Nobody: ${segment.district} is not a district in the ${districts.congress} Congress’s map`;
        }

        return `${segment.district} as the ${districts.congress} Congress drew it — only ZIP codes wholly inside it`;
    }

    const prefixes = segment.postcode_prefixes ?? [];

    if (prefixes.length === 0) {
        return 'Nobody: no ZIP codes named';
    }

    return prefixes.join(', ');
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Segments',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Segments" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Segments"
                :description="
                    segments.length === 1
                        ? '1 way this campaign has named to narrow its list'
                        : `${segments.length} ways this campaign has named to narrow its list`
                "
            />

            <Button as-child>
                <Link :href="create()">Name a segment</Link>
            </Button>
        </div>

        <div
            v-if="segments.length === 0"
            class="rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
        >
            <p class="text-sm text-muted-foreground">
                No segments yet. A segment is a group of ZIP codes, or a
                congressional district, that this campaign has named, so the
                supporter list can be narrowed to it without typing it again.
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
                        <th scope="col" class="px-4 py-3 font-medium">
                            Narrows to
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="segment in segments"
                        :key="segment.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">{{ segment.name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span :data-test="`segment-rule-${segment.id}`">{{
                                ruleSummary(segment)
                            }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div
                                class="flex items-center justify-end gap-3 text-sm"
                            >
                                <Link
                                    :href="edit(segment.id)"
                                    class="underline underline-offset-4"
                                    >Edit</Link
                                >

                                <!--
                                    Shown to every operator, unlike the Remove
                                    control on the supporter list. No segment
                                    ability discriminates between the two roles
                                    (D-25): removing a segment destroys no
                                    supporter, and anybody who may edit one can
                                    already strip its postcodes down to a rule
                                    matching nobody. A `usePermissions` branch
                                    here would be posture rather than a guard.
                                -->
                                <Form
                                    v-bind="
                                        SegmentController.destroy.form(
                                            segment.id,
                                        )
                                    "
                                    v-slot="{ processing }"
                                    :options="{ preserveScroll: true }"
                                >
                                    <button
                                        type="submit"
                                        :disabled="processing"
                                        class="text-destructive underline underline-offset-4 disabled:opacity-50"
                                        :data-test="`remove-segment-${segment.id}`"
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
    </div>
</template>
