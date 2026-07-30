<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BlastController from '@/actions/App/Http/Controllers/BlastController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, index } from '@/routes/blasts';
import type { Blast } from '@/types';

const { blast, audienceSize } = defineProps<{
    blast: Blast;
    audienceSize: number;
}>();

/**
 * The count as one sentence rather than a number beside some words.
 *
 * Built here so it renders as a single text node: a number in its own <span>
 * with the wording beside it reads identically to a person and is two nodes to
 * anything asserting on it, which makes the browser guard depend on markup
 * rather than on what the page says.
 */
const audienceSentence = computed(() =>
    audienceSize === 1
        ? '1 supporter matches this blast right now'
        : `${audienceSize} supporters match this blast right now`,
);

/**
 * A layout callback, not a static object, and the difference is load-bearing.
 *
 * defineOptions() is hoisted out of <script setup>, so it cannot see anything
 * declared there -- props included. Written the obvious way, `edit(blast.id)`
 * throws `ReferenceError: blast is not defined` at runtime, the page renders
 * nothing at all, and every server-side assertion still passes because the
 * route answered 200 with the right component name. Measured in a browser.
 *
 * Inertia's own answer is a callback, which is handed the page's props. It
 * returns props only, which Inertia merges onto the default layout app.ts
 * resolves by page name.
 */
defineOptions({
    layout: (props: { blast: Blast }) => ({
        breadcrumbs: [
            { title: 'Blasts', href: index() },
            { title: 'Edit blast', href: edit(props.blast.id) },
        ],
    }),
});
</script>

<template>
    <Head title="Edit blast" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Edit blast" :description="blast.subject" />

        <!--
            The recipient count, and it is presented as what it is.

            Under D-14 a blast holds a rule rather than a list of people, and
            the rule is worked out again when sending starts -- so this number
            is a prediction and not a promise. Somebody who unsubscribes between
            now and the send is correctly left out then, and this number moves.

            Saying "will go to" here would be the page making a guarantee the
            product deliberately does not make; the alternative that would make
            it true is freezing a recipient list at this moment, which mails
            people who have since asked not to be written to. So the wording
            carries the cost rather than hiding it.
        -->
        <div
            data-test="audience-size"
            class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <p class="text-sm font-medium">{{ audienceSentence }}</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Worked out again when the blast is sent, so this can change.
                Anyone who unsubscribes before then will not receive it.
            </p>
        </div>

        <Form
            v-bind="BlastController.update.form(blast.id)"
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
                    :default-value="blast.subject"
                />
                <InputError :message="errors.subject" />
            </div>

            <div class="grid gap-2">
                <Label for="body">Message</Label>
                <!-- See blasts/Create.vue for why this is a plain element. -->
                <textarea
                    id="body"
                    name="body"
                    required
                    rows="12"
                    class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                    :value="blast.body"
                />
                <InputError :message="errors.body" />
            </div>

            <div class="grid gap-2">
                <Label for="postcode_prefixes">Postcodes (optional)</Label>
                <!--
                    Joined back into the one line the operator typed, rather
                    than rendered as a list: this is the same field they wrote
                    in, so it has to read back the same way. `null` becomes an
                    empty field, which is what "everyone" looks like here.
                -->
                <Input
                    id="postcode_prefixes"
                    name="postcode_prefixes"
                    autocomplete="off"
                    placeholder="M15, EH8"
                    :default-value="(blast.postcode_prefixes ?? []).join(', ')"
                />
                <p class="text-sm text-muted-foreground">
                    Narrow the blast to supporters whose postcode starts with
                    one of these. Leave it empty to write to everyone. Case and
                    spacing do not matter.
                </p>
                <InputError :message="errors.postcode_prefixes" />
            </div>

            <p class="text-sm text-muted-foreground">
                Supporters who have unsubscribed are never included.
            </p>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing"
                    >Save changes</Button
                >
                <Button as-child variant="outline">
                    <Link :href="index()">Back to blasts</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
