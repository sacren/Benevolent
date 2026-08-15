<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SegmentController from '@/actions/App/Http/Controllers/SegmentController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, index } from '@/routes/segments';
import type { Segment } from '@/types';

const { segment, congress } = defineProps<{
    segment: Segment;
    congress: string | null;
}>();

/**
 * A layout callback, not a static object, and the difference is load-bearing.
 *
 * defineOptions() is hoisted out of <script setup>, so it cannot see anything
 * declared there -- props included. Written the obvious way, `edit(segment.id)`
 * throws `ReferenceError: segment is not defined` at runtime, the page renders
 * nothing at all, and every server-side assertion still passes because the
 * route answered 200 with the right component name. That defect shipped once in
 * this repository and was found by opening a page in a browser, not by a test.
 *
 * Inertia's own answer is a callback, which is handed the page's props.
 */
defineOptions({
    layout: (props: { segment: Segment }) => ({
        breadcrumbs: [
            { title: 'Segments', href: index() },
            { title: 'Edit segment', href: edit(props.segment.id) },
        ],
    }),
});
</script>

<template>
    <Head title="Edit segment" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Edit segment" :description="segment.name" />

        <Form
            v-bind="SegmentController.update.form(segment.id)"
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autocomplete="off"
                    :default-value="segment.name"
                />
                <InputError :message="errors.name" />
            </div>

            <!--
                The segment keeps the kind it was named with (D-37), so the form
                offers only the field that kind has. A prefix claims nothing
                about anybody's representation and a district claims everyone
                reached is a constituent, so turning one into the other would
                change what every draft pointing at it asserts.
            -->
            <div v-if="segment.district === null" class="grid gap-2">
                <Label for="postcode_prefixes">ZIP codes</Label>
                <!--
                    Joined with ", " because that is how the field is read back:
                    the server stores the prefixes as a list of what the operator
                    typed, and this is the one place the list becomes the line
                    again. Storing them folded would have shown an operator back
                    a prefix they did not write.
                -->
                <Input
                    id="postcode_prefixes"
                    name="postcode_prefixes"
                    required
                    autocomplete="off"
                    :default-value="
                        (segment.postcode_prefixes ?? []).join(', ')
                    "
                />
                <p class="text-sm text-muted-foreground">
                    Supporters whose ZIP code starts with one of these. Spacing
                    does not matter.
                </p>
                <InputError :message="errors.postcode_prefixes" />
            </div>

            <div v-else class="grid gap-2">
                <Label for="district">District</Label>
                <Input
                    id="district"
                    name="district"
                    required
                    autocomplete="off"
                    :default-value="segment.district"
                />
                <p class="text-sm text-muted-foreground">
                    A state and a district number, like MA-07, as the
                    {{ congress }} Congress drew it. The segment reaches only
                    supporters whose ZIP code lies wholly inside the district;
                    anyone in a ZIP code crossing its boundary is left out,
                    because their ZIP code cannot say which side they live on.
                </p>
                <InputError :message="errors.district" />
            </div>

            <p class="text-sm text-muted-foreground">
                A segment says where, not who may be written to. The supporter
                list shows everyone it names, including anyone who has
                unsubscribed; a blast never writes to them.
                <template v-if="segment.district !== null">
                    A blast aimed at this segment records the ZIP codes this
                    district claims at the moment it is sent, so editing the
                    segment afterwards does not change who that message went to.
                </template>
            </p>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing"
                    >Save changes</Button
                >
                <Button as-child variant="outline">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
