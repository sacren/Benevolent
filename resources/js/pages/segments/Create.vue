<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SegmentController from '@/actions/App/Http/Controllers/SegmentController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/segments';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Segments', href: index() },
            { title: 'Name a segment', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Name a segment" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Name a segment"
            description="A group of postcodes, saved under a name, so the supporter list can be narrowed to it without typing them again."
        />

        <Form
            v-bind="SegmentController.store.form()"
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
                    placeholder="What this campaign calls this group"
                />
                <p class="text-sm text-muted-foreground">
                    Names are unique within this campaign, so two segments
                    cannot be confused at the moment somebody picks one.
                </p>
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <!--
                    Required here where the same field on a blast is optional,
                    and the label says so rather than leaving it to the
                    validation error. An empty rule on a blast means everyone;
                    a segment that narrows to nothing is not a segment.
                -->
                <Label for="postcode_prefixes">Postcodes</Label>
                <Input
                    id="postcode_prefixes"
                    name="postcode_prefixes"
                    required
                    autocomplete="off"
                    placeholder="M15, EH8"
                />
                <p class="text-sm text-muted-foreground">
                    Supporters whose postcode starts with one of these. Case and
                    spacing do not matter.
                </p>
                <InputError :message="errors.postcode_prefixes" />
            </div>

            <!--
                Said on the page the segment is named on, because this is the
                one place the two consumers of a rule differ and the difference
                is easy to assume the wrong way round.
            -->
            <p class="text-sm text-muted-foreground">
                A segment says where, not who may be written to. The supporter
                list shows everyone it names, including anyone who has
                unsubscribed; a blast never writes to them.
            </p>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing"
                    >Save segment</Button
                >
                <Button as-child variant="outline">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
