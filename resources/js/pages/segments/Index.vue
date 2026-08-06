<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SegmentController from '@/actions/App/Http/Controllers/SegmentController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/segments';
import type { Segment } from '@/types';

defineProps<{
    segments: Segment[];
}>();

/**
 * How a segment describes who it narrows to, in a list with no room for a
 * count.
 *
 * **Deliberately a summary of the rule and not a size**, which is the blast
 * list's own choice for the same reason and is D-28's question rather than this
 * page's: a number beside each row is one query per row, and Phase 2 Step 6
 * measured what that costs on the list next door — 1,146 ms at ten rows, with
 * pagination making it worse rather than better.
 *
 * There is no "everyone" branch here, unlike the blast list's version of this
 * function. A blast's rule may be null and null means the whole contactable
 * list; `segments.postcode_prefixes` is NOT NULL, so a segment always names
 * somewhere. An empty list is still possible in the column and still means
 * nobody, so it is said in words rather than rendered as an empty cell that
 * would read as a rendering fault.
 */
function ruleSummary(segment: Segment): string {
    if (segment.postcode_prefixes.length === 0) {
        return 'Nobody: no postcodes named';
    }

    return segment.postcode_prefixes.join(', ');
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
                No segments yet. A segment is a group of postcodes this campaign
                has named, so it can be aimed at again without typing the
                postcodes out each time.
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
                            Postcodes
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
