<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import BlastController from '@/actions/App/Http/Controllers/BlastController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/blasts';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Blasts', href: index() },
            { title: 'Write a blast', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Write a blast" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Write a blast"
            description="Saved as a draft. Nothing is sent until you send it, and you can change it until then."
        />

        <Form
            v-bind="BlastController.store.form()"
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="subject">Subject</Label>
                <Input
                    id="subject"
                    name="subject"
                    required
                    autocomplete="off"
                    placeholder="What the message is about"
                />
                <InputError :message="errors.subject" />
            </div>

            <div class="grid gap-2">
                <Label for="body">Message</Label>
                <!--
                    A plain <textarea> rather than a ui/ component, because there
                    is one consumer. The classes are the Input component's, less
                    the parts that only make sense on a single-line field: no
                    fixed height and no file:* rules. The trigger to extract one
                    is the second multi-line field in this application.
                -->
                <textarea
                    id="body"
                    name="body"
                    required
                    rows="12"
                    placeholder="What you want to say to the people on this list"
                    class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                />
                <InputError :message="errors.body" />
            </div>

            <div class="grid gap-2">
                <Label for="postcode_prefixes">Postcodes (optional)</Label>
                <Input
                    id="postcode_prefixes"
                    name="postcode_prefixes"
                    autocomplete="off"
                    placeholder="M15, EH8"
                />
                <p class="text-sm text-muted-foreground">
                    Narrow the blast to supporters whose postcode starts with
                    one of these. Leave it empty to write to everyone. Case and
                    spacing do not matter.
                </p>
                <InputError :message="errors.postcode_prefixes" />
            </div>

            <!--
                Said on the page an operator writes on, not only on the one they
                aim from: this application will not write to somebody who asked
                it not to, and that is not a setting.
            -->
            <p class="text-sm text-muted-foreground">
                Supporters who have unsubscribed are never included.
            </p>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing"
                    >Save as draft</Button
                >
                <Button as-child variant="outline">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
