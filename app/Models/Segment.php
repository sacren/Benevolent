<?php

declare(strict_types=1);

namespace App\Models;

use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Segments\SegmentPolicy;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * one table -- and "everyone in 902" names a different set of human beings in
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
 * precinct for three months retypes the aim into every message and can correct it
 * in none of them.
 *
 * **What it may narrow on, and the asymmetry that must not be got wrong
 * (D-24, amended by D-37).** Postcode prefixes, or one congressional
 * district, and nothing else -- never both, which `segments_narrow_one_way_only`
 * makes unrepresentable. There is no subscription predicate here and no column
 * that could hold one: the supporter list may legitimately show people who
 * unsubscribed, while `App\Blasts\BlastAudience` enforces subscribed-only by
 * its shape rather than by a parameter, so one
 * stored rule carrying a status would mean two different things to two readers
 * and be one refactor from a message reaching somebody who asked not to be
 * contacted. A segment says *where*; whether somebody may be contacted at all
 * is the sending path's invariant and, on the list, a separate control that is
 * no part of any stored rule. There is likewise no name or email fragment here
 * -- searching is transient and may touch a person, segmenting is stored and
 * may not -- which is what keeps this table from becoming a sixth home for
 * supporter PII that no erasure path reaches. Step 6 settled that by running an
 * erasure rather than by this sentence, and the migration carries what it
 * showed along with the one residual it leaves standing. A district is a seat's
 * public name, `MA-07`, and names nobody either.
 *
 * **What a prefix rule means, which is the product's answer rather than an
 * incidental one.** A prefix matches a supporter when
 * `left(replace(postcode, ' ', ''), n)` equals it -- folded leading-character
 * equality, never a `like` pattern, because `%` and `_` are metacharacters
 * that widen a control whose whole purpose is to narrow. The fold gives up
 * tabs, newlines and a non-breaking space; it was chosen at 100.6 ms against
 * 250,000 supporters where `regexp_replace` cost 519.7 ms. (It read
 * `replace(lower(postcode), ...)` until D-33 removed `lower()` at Phase 4 Step
 * 2; this sentence was missed then.) App\Supporters\PostcodeNarrowing is where
 * it is written down.
 *
 * **What a district rule means is a claim, not a prefix (D-37).** A district
 * segment reaches the supporters App\Districts\DistrictClaim would place in
 * that seat -- a ZIP code whose whole area lies inside it -- and nobody whose
 * ZIP code crosses its boundary, by App\Districts\DistrictNarrowing. So it is
 * not a list of ZIP code prefixes under another name: `02141abc` begins with an
 * MA-07 ZIP code and is reached by a prefix segment on `02141`, and not by a
 * segment on MA-07, because it is not a ZIP code.
 *
 * **`#[UsePolicy]` arrived with the policy and `#[Fillable]` arrives with the
 * controller whose form mass-assigns**, which is the split `Blast` made for the
 * same reason. What the list permits is exactly what a person types: the name
 * and the rule, which is prefixes or a district. `operator_id` is stamped from
 * the signed-in operator by SegmentController::store() and is deliberately
 * absent, so a form cannot claim that somebody else named a segment.
 *
 * @property int $id
 * @property int|null $operator_id
 * @property string $name
 * @property list<string>|null $postcode_prefixes Null exactly when the segment narrows by district.
 * @property string|null $district The seat's name, `MA-07`; null exactly when it narrows by prefixes.
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
#[Fillable(['name', 'postcode_prefixes', 'district'])]
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

    /**
     * The blasts aimed at this narrowing.
     *
     * Added with its reader rather than with the column, which is the split
     * this project keeps making: `blasts.segment_id` arrived at Step 4's first
     * commit, and nothing on this side needed to look back along it until
     * SegmentController::destroy() had to say why a segment cannot be removed.
     * A relation with no reader is the shape this project keeps refusing.
     *
     * **It is a question, not a cascade.** The foreign key restricts on delete,
     * so this relation can never be used to remove the rows on the other end of
     * it -- a blast is a record of what a campaign said to people, and no
     * tidying of a narrowing gets to destroy one.
     *
     * @return HasMany<Blast, $this>
     */
    public function blasts(): HasMany
    {
        return $this->hasMany(Blast::class);
    }

    /**
     * The district this segment narrows to, as the relation names it -- or
     * null for a segment of ZIP code prefixes, and for one naming a seat the
     * relation does not have.
     *
     * Read back through Seat::parse() rather than trusted, which is how the
     * campaign's own seat is read (App\Tenancy\CampaignSeat): a seat a later
     * relation dropped, or one written by hand, is compared with nobody.
     */
    public function seat(ZctaDistricts $relation): ?Seat
    {
        return $this->district === null ? null : Seat::parse($this->district, $relation);
    }
}
