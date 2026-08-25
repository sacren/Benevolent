<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import OperatorInvitationController from '@/actions/App/Http/Controllers/OperatorInvitationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/operators';
import { create as inviteOperator } from '@/routes/operators/invitations';
import type { PendingInvitation, RosterOperator } from '@/types';

defineProps<{
    operators: RosterOperator[];
    invitations: PendingInvitation[];
}>();

/*
 * A campaign's roster (D-57): who runs it, and who has been invited to.
 *
 * Shown only to operators who may govern it -- OperatorPolicy refuses the
 * request otherwise -- so nothing here is hidden behind a permission check.
 */

function roleName(role: RosterOperator['role']): string {
    return role === 'owner' ? 'Owner' : 'Staff';
}

/**
 * Who let an operator in, and nothing more than the record says.
 *
 * **"Not recorded" is an answer, not a gap in the page (§7 criterion 4).** An
 * operator who joined before invitations were recorded, or who has changed
 * their address since, has no invitation this page can connect to them, and
 * inventing an inviter would fabricate the one thing a record of admission is
 * for.
 */
function admittedBy(operator: RosterOperator): string {
    if (operator.admitted === null) {
        return 'Not recorded';
    }

    return operator.admitted.by === null
        ? 'Invited by the platform'
        : `Invited by ${operator.admitted.by}`;
}

/*
 * Why a withdrawal changed nothing, when it did: the invitee used the link, or
 * somebody else withdrew it, between the page loading and the click. It is
 * shown once, above the list, rather than inside every row's form.
 */
const page = usePage();
const invitationError = computed(
    () => (page.props.errors as Record<string, string>).invitation,
);

function sentBy(invitation: PendingInvitation): string {
    return invitation.invited_by === null
        ? 'the platform'
        : invitation.invited_by;
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Operators', href: index() }],
    },
});
</script>

<template>
    <Head title="Operators" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Operators"
                :description="
                    operators.length === 1
                        ? '1 person runs this campaign'
                        : `${operators.length} people run this campaign`
                "
            />

            <Button as-child>
                <Link :href="inviteOperator()">Invite an operator</Link>
            </Button>
        </div>

        <div
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
                        <th scope="col" class="px-4 py-3 font-medium">Role</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            How they joined
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="operator in operators"
                        :key="operator.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        :data-test="`operator-${operator.id}`"
                    >
                        <td class="px-4 py-3">
                            {{ operator.name }}
                            <span
                                v-if="operator.is_you"
                                class="text-muted-foreground"
                                >(you)</span
                            >
                        </td>
                        <td class="px-4 py-3">{{ operator.email }}</td>
                        <td
                            class="px-4 py-3"
                            :data-test="`operator-role-${operator.id}`"
                        >
                            {{ roleName(operator.role) }}
                        </td>
                        <td
                            class="px-4 py-3 text-muted-foreground"
                            :data-test="`operator-admitted-${operator.id}`"
                        >
                            {{ admittedBy(operator) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-sm text-muted-foreground">
            "Not recorded" means this campaign holds no invitation it can match
            to that person: they joined before invitations were recorded, or
            have changed their email address since.
        </p>

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Invitations not yet used"
                description="Each link works once. Whether it reached their inbox is up to their mail provider, and this page cannot see that. Withdrawing one stops its link working, and lets you send that person a fresh one."
            />

            <InputError
                :message="invitationError"
                data-test="invitation-refusal"
            />

            <p
                v-if="invitations.length === 0"
                class="text-sm text-muted-foreground"
                data-test="no-pending-invitations"
            >
                Nobody is waiting on an invitation.
            </p>

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
                            <th scope="col" class="px-4 py-3 font-medium">
                                Email
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Joins as
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Sent by
                            </th>
                            <th scope="col" class="px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            :data-test="`invitation-${invitation.id}`"
                        >
                            <td class="px-4 py-3">{{ invitation.email }}</td>
                            <td class="px-4 py-3">
                                {{ roleName(invitation.role) }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ sentBy(invitation) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Form
                                    v-bind="
                                        OperatorInvitationController.destroy.form(
                                            invitation.id,
                                        )
                                    "
                                    v-slot="{ processing }"
                                    :options="{ preserveScroll: true }"
                                >
                                    <button
                                        type="submit"
                                        :disabled="processing"
                                        class="text-destructive underline underline-offset-4 disabled:opacity-50"
                                        :data-test="`withdraw-invitation-${invitation.id}`"
                                    >
                                        Withdraw
                                    </button>
                                </Form>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
