<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SupporterController from '@/actions/App/Http/Controllers/SupporterController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, index } from '@/routes/supporters';
import type { Supporter } from '@/types';

const { supporter } = defineProps<{
    supporter: Supporter;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Supporters', href: index() },
            { title: 'Edit supporter', href: edit(supporter.id) },
        ],
    },
});
</script>

<template>
    <Head title="Edit supporter" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Edit supporter"
            :description="supporter.name ?? supporter.email"
        />

        <Form
            v-bind="SupporterController.update.form(supporter.id)"
            class="max-w-xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="off"
                    :default-value="supporter.email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    autocomplete="off"
                    :default-value="supporter.name ?? ''"
                />
                <InputError :message="errors.name" />
            </div>

            <!--
                The parts are shown so a blank one can be filled in when the
                campaign learns it, and are never derived from the display name
                above. Nothing in this application splits a name.
            -->
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="given_name">Given name</Label>
                    <Input
                        id="given_name"
                        name="given_name"
                        autocomplete="off"
                        :default-value="supporter.given_name ?? ''"
                    />
                    <InputError :message="errors.given_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="family_name">Family name</Label>
                    <Input
                        id="family_name"
                        name="family_name"
                        autocomplete="off"
                        :default-value="supporter.family_name ?? ''"
                    />
                    <InputError :message="errors.family_name" />
                </div>
            </fieldset>

            <div class="grid gap-2">
                <Label for="postcode">Postcode</Label>
                <Input
                    id="postcode"
                    name="postcode"
                    autocomplete="off"
                    :default-value="supporter.postcode ?? ''"
                />
                <InputError :message="errors.postcode" />
            </div>

            <!--
                Unsubscribing is how a campaign stops contacting someone, and it
                is deliberately here rather than on the create form. Keeping the
                supporter with this status is what stops a later import putting
                them straight back on the list -- which is also why removing
                them is the exceptional act rather than the ordinary one.
            -->
            <div class="grid gap-2">
                <Label for="subscription_status">Status</Label>
                <Select
                    name="subscription_status"
                    :default-value="supporter.subscription_status"
                >
                    <SelectTrigger id="subscription_status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="subscribed">
                            Subscribed — the campaign may contact them
                        </SelectItem>
                        <SelectItem value="unsubscribed">
                            Unsubscribed — they asked not to be contacted
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.subscription_status" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-supporter-button"
                >
                    Save
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
