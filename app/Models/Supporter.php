<?php

declare(strict_types=1);

namespace App\Models;

use App\Supporters\SubscriptionStatus;
use App\Supporters\SupporterPolicy;
use Database\Factories\SupporterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone a campaign is trying to reach.
 *
 * Sits in app/Models/ while the vocabulary it speaks lives in app/Supporters/,
 * the same split this application already makes twice over — OperatorRole and
 * Permission in app/Authorization/, AuditEvent in app/Audit/, the models they
 * describe here. Eloquent models are where the framework and every convention
 * look for them; the domain's words follow the module.
 *
 * **This model deliberately names no connection.** That is not an omission: it
 * means a supporter follows the default connection, which tenancy has already
 * switched onto the campaign serving the request, so a campaign's list lands in
 * the campaign's own database. Naming a connection here — central, most
 * plausibly, since "the supporter list" sounds like platform data — would pool
 * every campaign's supporters into one table and hand any reader another
 * campaign's people. AuditEntry says the same thing from the other side, and
 * the migration says it from the schema's.
 *
 * The name columns carry an invariant worth stating where it can be read:
 * `name` is current truth, and `given_name` and `family_name` are provenance —
 * what the source told us. Nothing derives one from the other, nothing splits a
 * name, and nothing edits the parts in this phase. With one writer for the
 * parts and one for the display string, the two cannot disagree by accident.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $given_name
 * @property string|null $family_name
 * @property string $email
 * @property string|null $postcode
 * @property SubscriptionStatus $subscription_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Named here rather than discovered by convention, the same way the audit
// observer is attached to User. A policy filed at App\Policies\SupporterPolicy
// would be found by path guessing whether or not this line existed, which would
// make it impossible to prove the wiring does anything; with the policy beside
// its module, deleting this line turns the allow tests red.
#[UsePolicy(SupporterPolicy::class)]
#[Fillable(['name', 'given_name', 'family_name', 'email', 'postcode', 'subscription_status'])]
class Supporter extends Model
{
    /** @use HasFactory<SupporterFactory> */
    use HasFactory;

    /**
     * Find a supporter by the address, the way the campaign's index matches it.
     *
     * **A plain `where('email', ...)` does not find a case variant, and that is
     * measured rather than feared.** D-8 made the address the identity and put
     * the uniqueness constraint on `lower(email)`, so `Jean@Example.test` and
     * `jean@example.test` are one supporter to the database and two to any
     * query that compares the column directly. A lookup written the obvious way
     * therefore reports "not on the list" about somebody who is, and the
     * consequence lands one step later: the insert that follows is refused by
     * the index with SQLSTATE 23505, which reaches an operator as a 500 rather
     * than as an answer.
     *
     * Step 1 deliberately did not write this scope, because a scope with no
     * consumer is a guess at how someone will want to query. Its first consumer
     * is the demo seeder, deciding whether it has already added a supporter,
     * and the create and edit forms will be its second -- so it exists to give
     * that knowledge one home rather than leave copies of a raw fragment free
     * to drift apart.
     *
     * Both sides are folded, not just the column: the address arrives from a
     * form or a file exactly as somebody typed it, so folding the column alone
     * would still miss the variant this exists to catch.
     *
     * @param  Builder<Supporter>  $query
     */
    #[Scope]
    protected function whereEmailMatches(Builder $query, string $email): void
    {
        $query->whereRaw('lower(email) = lower(?)', [$email]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscription_status' => SubscriptionStatus::class,
        ];
    }
}
