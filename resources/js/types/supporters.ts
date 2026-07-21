/**
 * A supporter as the server sends one.
 *
 * Every name field is nullable and that is the schema being honest rather than
 * loose: `name` is what the source called this person, while `given_name` and
 * `family_name` are provenance — recorded only when the source actually split
 * them, never inferred from a single string. A row with an address and no name
 * at all is valid, because a petition widget that asked only for an email
 * produces exactly that and the person is still contactable.
 */
export type Supporter = {
    id: number;
    name: string | null;
    given_name: string | null;
    family_name: string | null;
    email: string;
    postcode: string | null;
    subscription_status: SubscriptionStatus;
    created_at: string;
    updated_at: string;
};

/**
 * Mirrors App\Supporters\SubscriptionStatus. Two cases, deliberately: a third,
 * `Bounced`, waits for a sending path that could write it.
 */
export type SubscriptionStatus = 'subscribed' | 'unsubscribed';
