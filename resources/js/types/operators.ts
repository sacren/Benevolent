/**
 * One of a campaign's operators, as the roster shows them.
 *
 * `role` is sent because the roster is the page *about* roles; nothing here
 * decides what the viewer may do, which is `auth.permissions`' job alone.
 *
 * `admitted` is who let them in, read from the invitation they accepted.
 * **Null means nothing records it** — they joined before invitations were
 * recorded, or have changed their address since — and it is shown as exactly
 * that, never as a guess (§7 criterion 4). A non-null `admitted` with a null
 * `by` was sent by the platform, which invites a campaign's first Owner.
 */
export type RosterOperator = {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'staff';
    is_you: boolean;
    admitted: { by: string | null } | null;
};

/**
 * An invitation that has not been used, as the roster shows it.
 *
 * A null `invited_by` is the platform's, for the reason `admitted.by` above
 * can be null.
 */
export type PendingInvitation = {
    id: number;
    email: string;
    role: 'owner' | 'staff';
    invited_by: string | null;
    /** Past its lifetime (D-59): it still holds a credential, and the link opens nothing. */
    expired: boolean;
};
