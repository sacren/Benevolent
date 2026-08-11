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

const { segment } = defineProps<{
    segment: Segment;
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

            <div class="grid gap-2">
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
                    :default-value="segment.postcode_prefixes.join(', ')"
                />
                <p class="text-sm text-muted-foreground">
                    Supporters whose ZIP code starts with one of these. Spacing
                    does not matter.
                </p>
                <InputError :message="errors.postcode_prefixes" />
            </div>

            <p class="text-sm text-muted-foreground">
                A segment says where, not who may be written to. The supporter
                list shows everyone it names, including anyone who has
                unsubscribed; a blast never writes to them.
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
