<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import OperatorInvitationController from '@/actions/App/Http/Controllers/OperatorInvitationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/operators';
import { create } from '@/routes/operators/invitations';

/*
 * The form a campaign uses to admit somebody (D-53 Axis 1 (i)).
 *
 * **What it says about delivery is D-56, and it is deliberately narrow.** The
 * invitation is handed to the mail transport before this page hears back, and
 * a transport that refuses it leaves no invitation at all -- so "sent" is true
 * in the only sense the product can know. Whether it arrived is not something
 * this platform can see (deferral 30), and the page says so rather than letting
 * a success message imply it.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operators', href: index() },
            { title: 'Invite an operator', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Invite an operator" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Invite an operator"
            description="Send somebody a link that lets them join this campaign and choose their own password."
        />

        <Form
            v-bind="OperatorInvitationController.store.form()"
            :reset-on-success="['email']"
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="email">Their email address</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autocomplete="off"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">They join as</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="role"
                        value="staff"
                        checked
                        data-test="invite-as-staff"
                    />
                    Staff, who do the campaign's work
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="role"
                        value="owner"
                        data-test="invite-as-owner"
                    />
                    An Owner, who can do everything Staff can and also govern
                    the campaign, including who else runs it
                </label>
                <InputError :message="errors.role" />
            </fieldset>

            <p class="text-sm text-muted-foreground">
                The invitation is handed to the mail service as soon as you send
                it; if that fails, nothing is recorded and you can try again.
                Whether it reaches their inbox is up to their mail provider, and
                this page cannot see that. The link works once.
            </p>

            <Button
                type="submit"
                :disabled="processing"
                data-test="invite-button"
            >
                Send invitation
            </Button>
        </Form>
    </div>
</template>
