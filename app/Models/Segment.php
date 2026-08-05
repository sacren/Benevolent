<?php

declare(strict_types=1);

namespace App\Models;

use App\Segments\SegmentPolicy;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A narrowing of a campaign's supporter list that the campaign has named.
 *
 * Sits in app/Models/ while the vocabulary this module grows follows the
 * module, the split this application already makes four times over.
 * `app/Segments/` did not exist when this model was written, deliberately
 * rather than pending: both earlier modules created their directory at their
 * own first step to hold a *status enum*, and a segment has no status because
 * it is not a thing that runs. It arrived at the next step with the first thing
 * that needed filing in it, which is the policy below.
 *
 * **This model deliberately names no connection.** That is the single most
 * consequential line here, and the plausible mistake has a flavour of its own:
 * a *rule* looks like configuration rather than like data, and configuration
 * sounds central. Naming a connection would pool every campaign's segments into
 * one table -- and "everyone in M15" names a different set of human beings in
 * each campaign, so the pooled row would be read by campaigns that share
 * nothing but a postcode. Naming none means a segment follows the default
 * connection tenancy has already switched onto the campaign serving the
 * request. Supporter, AuditEntry and Blast say the same thing from their own
 * side, and the migration says it from the schema's.
 *
 * **A segment is a saved object rather than a filter typed twice (D-23).** The
 * test applied was whether anything in the product would read a segment that
 * the operator is not looking at when they create it, answered against the
 * repository rather than against what a campaign might want: `SendBlast`,
 * `BlastController::edit` and `BlastController::send` already read a stored
 * narrowing rule, the first of them in a worker with nobody watching. What a
 * name adds is a single place the aim is written down -- `blasts.postcode_prefixes`
 * is per blast, and nothing duplicates a blast, so a campaign aiming at one
 * ward for three months retypes the aim into every message and can correct it
 * in none of them.
 *
 * **What it may narrow on, and the asymmetry that must not be got wrong
 * (D-24).** Postcode prefixes, and nothing else. There is no subscription
 * predicate here and no column that could hold one: the supporter list may
 * legitimately show people who unsubscribed, while `App\Blasts\BlastAudience`
 * enforces subscribed-only by its shape rather than by a parameter, so one
 * stored rule carrying a status would mean two different things to two readers
 * and be one refactor from a message reaching somebody who asked not to be
 * contacted. A segment says *where*; whether somebody may be contacted at all
 * is the sending path's invariant and, on the list, a separate control that is
 * no part of any stored rule. There is likewise no name or email fragment here
 * -- searching is transient and may touch a person, segmenting is stored and
 * may not -- which is what keeps this table from becoming a sixth home for
 * supporter PII that no erasure path reaches.
 *
 * **What the rule means, which is now the product's answer rather than an
 * incidental one.** A prefix matches a supporter when
 * `left(replace(lower(postcode), ' ', ''), n)` equals it -- folded leading-character
 * equality, the rule `BlastAudience` already uses, and never a `like` pattern,
 * because `%` and `_` are metacharacters that widen a control whose whole
 * purpose is to narrow. The fold gives up tabs, newlines and a non-breaking
 * space; it was chosen at 100.6 ms against 250,000 supporters where
 * `regexp_replace` cost 519.7 ms. Storing a rule under a name is what turns
 * that from an implementation detail of one class into the product's definition
 * of what a postcode prefix means, which is why D-24 promotes it to a variation
 * point rather than leaving it filed as a candidate.
 *
 * **`#[UsePolicy]` arrives here with the policy, and `#[Fillable]` still does
 * not**, which is the split `Blast` made for the same reason: the first belongs
 * with the policy, the second with the controller whose form would mass-assign,
 * and that controller does not exist yet.
 *
 * @property int $id
 * @property int|null $operator_id
 * @property string $name
 * @property list<string> $postcode_prefixes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Filed with the module rather than at the path Gate::getPolicyFor() would
// guess, and this attribute is what makes that filing testable (D-5). Measured
// on this model rather than inherited: with nothing anywhere the gate returns
// null for a Segment, and a class declared at App\Policies\SegmentPolicy is
// handed back *with no attribute present at all* -- so a policy filed by
// convention would make this line decorative and deletable with every test
// still green. Here, deleting it turns the allow tests red.
#[UsePolicy(SegmentPolicy::class)]
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'postcode_prefixes' => 'array',
        ];
    }
}
