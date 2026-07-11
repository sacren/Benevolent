<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        title: 'Sign in on your campaign',
        description: 'Every campaign has its own web address',
    },
});
</script>

<template>
    <Head title="Sign in on your campaign" />

    <div class="space-y-4 text-sm text-muted-foreground">
        <p>
            Campaign staff sign in on their own campaign's address rather than
            here. Your account belongs to your campaign, so there is nothing on
            this page to sign in to.
        </p>
        <p>
            Open your campaign's address and add
            <span class="font-medium text-foreground">/login</span> to it. If
            you don't know the address, ask whoever set the campaign up.
        </p>
    </div>
</template>
