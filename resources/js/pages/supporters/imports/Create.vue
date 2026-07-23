<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SupporterImportController from '@/actions/App/Http/Controllers/SupporterImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/supporters';
import { create } from '@/routes/supporters/imports';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Supporters', href: index() },
            { title: 'Import a list', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Import a list" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Import a list"
            description="Upload the file first. You will be asked what its columns mean once we can show you what they are."
        />

        <Form
            v-bind="SupporterImportController.store.form()"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="file">List file</Label>
                <Input
                    id="file"
                    type="file"
                    name="file"
                    accept=".csv,text/csv,text/plain"
                    required
                />
                <p class="text-sm text-muted-foreground">
                    A CSV export with a heading row. Nothing is read from it
                    until you have said which column is which.
                </p>
                <InputError :message="errors.file" />
            </div>

            <!--
                Said here rather than discovered afterwards, because these are
                the two things about an import that surprise people. Somebody
                already on the list is corrected rather than added twice, since
                the address is how the campaign tells one supporter from
                another; and somebody who has asked not to be contacted stays
                that way, which is the whole reason unsubscribing is a status
                rather than a deletion.
            -->
            <div
                class="rounded-xl border border-sidebar-border/70 p-4 text-sm text-muted-foreground dark:border-sidebar-border"
            >
                <p class="mb-2 font-medium text-foreground">
                    What importing does
                </p>
                <ul class="list-inside list-disc space-y-1">
                    <li>
                        Someone already on the list is updated, not added a
                        second time — they are matched on their email address,
                        ignoring capitalisation.
                    </li>
                    <li>
                        A blank cell leaves what you already had. It never
                        clears it.
                    </li>
                    <li>
                        Anyone who has unsubscribed stays unsubscribed, whatever
                        the file says.
                    </li>
                    <li>
                        Rows without a usable email address are skipped, and
                        counted so you can see how many.
                    </li>
                </ul>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="upload-list-button">
                    Upload
                </Button>

                <Link
                    :href="index()"
                    class="text-sm underline underline-offset-4"
                    >Cancel</Link
                >
            </div>
        </Form>
    </div>
</template>
