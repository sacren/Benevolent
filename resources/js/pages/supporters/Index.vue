<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/supporters';
import type { Supporter } from '@/types';

defineProps<{
    supporters: Supporter[];
}>();

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
            <Heading
                title="Supporters"
                :description="
                    supporters.length === 1
                        ? '1 person on this campaign’s list'
                        : `${supporters.length} people on this campaign’s list`
                "
            />

            <Button as-child>
                <Link :href="create()">Add supporter</Link>
            </Button>
        </div>

        <div
            v-if="supporters.length === 0"
            class="rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border"
        >
            <p class="text-sm text-muted-foreground">
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
                            Postcode
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
                        v-for="supporter in supporters"
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
                            <Link
                                :href="edit(supporter.id)"
                                class="text-sm underline underline-offset-4"
                                >Edit</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
