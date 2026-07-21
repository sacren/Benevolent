export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;

    /**
     * What this operator may do, as App\Authorization\Permission's backing
     * strings — resolved on the server through the same gates the policies
     * consult.
     *
     * Components branch on these and never on a role. A role comparison in a
     * component is a second copy of the role-to-permission map with no test
     * over it, silently wrong the day a third role exists or a grant moves;
     * `role` is deliberately absent from User above so that branch cannot be
     * written by accident. Empty on central pages, which have no campaign and
     * so no operator.
     */
    permissions: string[];
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
