<script setup lang="ts">
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';
import { ref } from 'vue';
import SupporterImportController from '@/actions/App/Http/Controllers/SupporterImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/supporters';
import type { NameColumnMode, SupporterImport } from '@/types';

const props = defineProps<{
    import: SupporterImport;
    operator: string | null;
    finished: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Supporters', href: index() }],
    },
});

/**
 * Which name columns to ask for. Held here so the form can show the right
 * fields as the operator changes their mind, while the Select still submits
 * through its own name.
 */
const nameMode = ref<NameColumnMode>('split');

/**
 * Ask the server again while the file is still being read.
 *
 * Whether it is still being read comes from the server's own `finished` prop
 * rather than from comparing the status against a list of terminal states here
 * — a list in the browser is one new status away from polling forever.
 *
 * Only the fields that change are re-fetched, and polling stops as soon as the
 * import reaches a state it will not leave.
 */
const { stop } = usePoll(
    2000,
    {
        only: ['import', 'finished'],
        onSuccess: () => {
            if (props.finished) {
                stop();
            }
        },
    },
    { autoStart: !props.finished },
);
</script>

<template>
    <Head :title="props.import.original_filename" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="props.import.original_filename"
                :description="
                    props.operator
                        ? `Uploaded by ${props.operator}`
                        : 'Uploaded by an operator who has since been removed'
                "
            />

            <Badge
                :variant="
                    props.import.status === 'failed'
                        ? 'destructive'
                        : 'secondary'
                "
                data-test="import-status"
            >
                {{
                    {
                        awaiting_mapping: 'Waiting for you',
                        pending: 'Queued',
                        running: 'Reading the file',
                        completed: 'Finished',
                        failed: 'Stopped',
                    }[props.import.status]
                }}
            </Badge>
        </div>

        <!--
            Waiting to be told what the columns mean. The choices below are this
            file's own heading row, so nothing here is a guess about what a
            column called "Name" might hold.
        -->
        <div v-if="props.import.status === 'awaiting_mapping'">
            <Form
                v-bind="SupporterImportController.start.form(props.import.id)"
                class="max-w-xl space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="email"
                        >Which column holds the email address?</Label
                    >
                    <Select name="email">
                        <SelectTrigger id="email">
                            <SelectValue placeholder="Choose a column" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="header in props.import.headers"
                                :key="header"
                                :value="header"
                            >
                                {{ header }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-sm text-muted-foreground">
                        This is how the campaign tells one supporter from
                        another, so it is the one column an import cannot do
                        without.
                    </p>
                    <InputError :message="errors.email" />
                </div>

                <!--
                    The operator says how the file carries the name; nothing
                    here works it out. Splitting a single name column, or
                    joining two that were never meant to be joined, invents
                    information about a real person that nothing can recover
                    afterwards -- which is exactly what the three name columns
                    exist to prevent.
                -->
                <div class="grid gap-2">
                    <Label for="name_mode"
                        >How does the file carry names?</Label
                    >
                    <Select v-model="nameMode" name="name_mode">
                        <SelectTrigger id="name_mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="split">
                                Two columns — a given name and a family name
                            </SelectItem>
                            <SelectItem value="single">
                                One column — the whole name as it should be
                                shown
                            </SelectItem>
                            <SelectItem value="none">
                                No name at all — just addresses
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.name_mode" />
                </div>

                <div v-if="nameMode === 'single'" class="grid gap-2">
                    <Label for="name">Which column holds the name?</Label>
                    <Select name="name">
                        <SelectTrigger id="name">
                            <SelectValue placeholder="Choose a column" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="header in props.import.headers"
                                :key="header"
                                :value="header"
                            >
                                {{ header }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-sm text-muted-foreground">
                        Stored exactly as the file gives it. It will not be
                        split into parts.
                    </p>
                    <InputError :message="errors.name" />
                </div>

                <div
                    v-if="nameMode === 'split'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label for="given_name">Given name column</Label>
                        <Select name="given_name">
                            <SelectTrigger id="given_name">
                                <SelectValue placeholder="Choose a column" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="header in props.import.headers"
                                    :key="header"
                                    :value="header"
                                >
                                    {{ header }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.given_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="family_name">Family name column</Label>
                        <Select name="family_name">
                            <SelectTrigger id="family_name">
                                <SelectValue placeholder="Choose a column" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="header in props.import.headers"
                                    :key="header"
                                    :value="header"
                                >
                                    {{ header }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.family_name" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="postcode">
                        Which column holds the postcode?
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <Select name="postcode">
                        <SelectTrigger id="postcode">
                            <SelectValue placeholder="The file has none" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="header in props.import.headers"
                                :key="header"
                                :value="header"
                            >
                                {{ header }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.postcode" />
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        :disabled="processing"
                        data-test="start-import-button"
                    >
                        Start the import
                    </Button>

                    <Link
                        :href="index()"
                        class="text-sm underline underline-offset-4"
                        >Cancel</Link
                    >
                </div>
            </Form>
        </div>

        <!--
            Reading, or queued behind other work. The counts come from the
            record, which the job writes once per chunk rather than once at the
            end, so this moves while a large file is being read.
        -->
        <div v-else class="max-w-xl space-y-6">
            <dl
                class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-sidebar-border/70 bg-sidebar-border/70 sm:grid-cols-4 dark:border-sidebar-border dark:bg-sidebar-border"
            >
                <div class="bg-background p-4">
                    <dt class="text-xs text-muted-foreground uppercase">
                        Rows read
                    </dt>
                    <dd class="text-2xl" data-test="rows-read">
                        {{ props.import.rows_read }}
                    </dd>
                </div>
                <div class="bg-background p-4">
                    <dt class="text-xs text-muted-foreground uppercase">
                        Added
                    </dt>
                    <dd class="text-2xl" data-test="supporters-added">
                        {{ props.import.supporters_added }}
                    </dd>
                </div>
                <div class="bg-background p-4">
                    <dt class="text-xs text-muted-foreground uppercase">
                        Updated
                    </dt>
                    <dd class="text-2xl" data-test="supporters-updated">
                        {{ props.import.supporters_updated }}
                    </dd>
                </div>
                <div class="bg-background p-4">
                    <dt class="text-xs text-muted-foreground uppercase">
                        Skipped
                    </dt>
                    <dd class="text-2xl" data-test="rows-skipped">
                        {{ props.import.rows_skipped }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="props.import.rows_skipped > 0"
                class="text-sm text-muted-foreground"
            >
                Skipped rows had no usable email address, so there was nobody in
                them to add. Nothing else in the file was affected.
            </p>

            <!--
                Said here rather than only in a central log an operator cannot
                reach. Whatever was read before it stopped has been kept, which
                is worth saying plainly: the alternative reads as though the
                whole upload was lost.
            -->
            <div
                v-if="props.import.status === 'failed'"
                class="rounded-xl border border-destructive/40 p-4"
            >
                <p class="mb-1 font-medium">This import stopped early</p>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="failure-reason"
                >
                    {{ props.import.failure_reason }}
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    Everything read before it stopped is on your list. You can
                    upload the file again once the problem is fixed.
                </p>
            </div>

            <p
                v-else-if="!props.finished"
                class="text-sm text-muted-foreground"
                data-test="still-running"
            >
                This page updates itself while the file is being read.
            </p>

            <Button as-child variant="outline">
                <Link :href="index()">Back to supporters</Link>
            </Button>
        </div>
    </div>
</template>
