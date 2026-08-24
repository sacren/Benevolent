<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/*
 * The page somebody a campaign invited lands on from the link in their mail.
 *
 * **It declares no layout, and `app.ts` registers its name in the resolver's
 * `null` arm in the same edit, for the reason Unsubscribe.vue gives.** The
 * resolver's default arm is AppLayout, the signed-in application shell, which
 * would render a campaign's sidebar and an account menu to somebody who has no
 * account yet -- while the route still answers 200 with the right component
 * name, so no server-side assertion can see it. A browser test is what proves
 * it. Not AuthLayout either: that carries the platform's logo and a link to the
 * platform's home page, and this is a campaign's page shown to somebody the
 * campaign asked for.
 *
 * **There is no address field, and that is the design rather than an
 * omission.** The address is the invitation's; the page shows it so the person
 * knows which one they are joining as, and nothing here could submit another.
 */
defineProps<{
    token: string;
    email: string;
    role: 'owner' | 'staff';
    campaignName: string;
    passwordRules: string;
}>();
</script>

<template>
    <Head title="Join a campaign" />

    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 text-foreground md:p-10"
    >
        <div class="w-full max-w-md space-y-6">
            <div class="space-y-2 text-center">
                <h1 class="text-xl font-medium">{{ campaignName }}</h1>
                <p class="text-sm text-muted-foreground">
                    You have been invited to help run this campaign as
                    <span
                        class="font-medium text-foreground"
                        data-test="invitation-role"
                        >{{ role === 'owner' ? 'an Owner' : 'Staff' }}</span
                    >.
                </p>
            </div>

            <Form
                v-bind="InvitationController.store.form(token)"
                :reset-on-success="['password', 'password_confirmation']"
                v-slot="{ errors, processing }"
                class="space-y-6 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
                data-test="invitation-accept"
            >
                <div class="grid gap-2">
                    <Label>Signing in as</Label>
                    <p class="text-sm font-medium" data-test="invitation-email">
                        {{ email }}
                    </p>
                    <!--
                        Where the writer's refusal lands: an address that already
                        belongs to one of this campaign's operators. There is no
                        email field for it to sit under, so it sits under the
                        address it is about.
                    -->
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Your name</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        name="name"
                        placeholder="Full name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Choose a password</Label>
                    <PasswordInput
                        id="password"
                        required
                        autocomplete="new-password"
                        name="password"
                        placeholder="Password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Confirm password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="invitation-accept-button"
                >
                    <Spinner v-if="processing" />
                    Join {{ campaignName }}
                </Button>
            </Form>
        </div>
    </div>
</template>
