<?php

declare(strict_types=1);

namespace App\Segments;

use App\Authorization\Permission;
use App\Models\Segment;
use App\Models\User;

/**
 * What an operator may do to the segments of the campaign they are in.
 *
 * Every answer comes from a Permission, never from reading the operator's role,
 * for the reason SupporterPolicy and BlastPolicy both give: asking "may this
 * operator name a segment?" survives the day a third role appears, while asking
 * "is this operator an Owner?" has to be found and rewritten everywhere
 * somebody wrote it.
 *
 * **This class lives with its module rather than in app/Policies/, and that is
 * load-bearing rather than tidy (D-5).** Gate::getPolicyFor() consults an
 * explicit registration, then the #[UsePolicy] attribute, then path guessing.
 * Measured for this model rather than inherited from Supporter's or Blast's
 * measurement, because a resolution order is the kind of claim that gets
 * believed without being checked: with nothing anywhere, the guess for
 * App\Models\Segment is App\Models\Policies\SegmentPolicy; declare a class at
 * App\Policies\SegmentPolicy and delete the attribute, and the gate hands back
 * that class -- found *with no attribute present at all*. So a policy filed by
 * convention would make the attribute decorative, deletable with every test
 * still green. Filed here, the attribute is the only thing connecting the two.
 *
 * **The abilities below ride on the supporter permissions rather than on
 * segment permissions of their own, and that is D-25 rather than an omission.**
 * A segment is a new *surface* over an authority this product already has, not
 * a new authority. The only thing a segment can do today is narrow the
 * supporter list, which is exactly what ViewSupporters and EditSupporters are
 * the authority for; and every ground this application has ever used to
 * withhold an ability is absent here. Nothing leaves the platform when a
 * segment is saved, so ExportSupporters' ground does not apply. Nothing is
 * unrecoverable -- a name and a list of prefixes retype in seconds -- so
 * DeleteSupporters' does not. There is no act that cannot be recalled, so
 * SendBlasts' does not. And D-24 keeps every string a campaign typed about a
 * person out of the rule, so reading a segment discloses nothing the
 * unpaginated supporter list does not already disclose to anybody holding
 * ViewSupporters.
 *
 * **The argument that looked like it might produce a new authority, and where
 * it actually lands.** A segment is a shared, mutable object, so editing one
 * changes what another operator's draft blast would do. Measured against the
 * repository, that reaches no outcome a Staff operator cannot already reach:
 * EditBlasts is deliberately theirs, so re-aiming any draft's postcode_prefixes
 * is already unconditionally Staff's, one object along. What a segment would
 * add is *leverage* -- several drafts re-aimed by one action, where the author
 * may not notice -- and that is a consequence rather than an authority, owned
 * by D-27 at a later step. Withholding through a segment what Staff already
 * holds through the blast would be posture invented to make a test interesting.
 *
 * **The trigger for segments to take their own Permission cases** is D-26
 * giving a blast a pointer at a segment. At that moment a segment acquires a
 * second consumer in another module and the authority to edit one stops being
 * "keeping supporter details current", which is the phrase EditSupporters' own
 * docblock uses. It names a thing the system would move -- a pointer existing --
 * rather than an ordinal, and buying the split then, with a measured
 * consequence, is better than buying it now with module symmetry.
 *
 * **This policy answers exactly the four abilities below, and any other ability
 * checked against a Segment is denied -- silently, and indistinguishably from a
 * considered refusal.** Measured at Phase 1 Step 2: an absent ability and a
 * nonsense ability both return false and throw the same AuthorizationException.
 * A `__call` remedy was built, confirmed working and declined twice, because it
 * is implicit machinery in a codebase that has chosen explicit wiring. So
 * adding any surface needing an ability not listed below means adding the
 * method *and* its allow test in the same edit; the missing method will not
 * announce itself. `view` is deliberately absent, because this module ships no
 * single-segment page for it to govern -- an omission that, unlike a missing
 * enum case, fails silently, which is why it is named here.
 *
 * **No ability here discriminates between the two roles, and that is reported
 * rather than fixed.** What keeps the tests below from being decoration is that
 * the allows are asserted for *Staff*: Staff holds exactly the four permissions
 * that discriminate nobody, so an ability mis-mapped onto a withheld one --
 * delete() onto DeleteSupporters is the tempting mistake -- turns a Staff allow
 * red. The one defect no behavioural test here can catch is a method rewritten
 * to `return true`, which is byte-equivalent in behaviour today and diverges
 * only once a grant moves -- measured, and guarded by nothing: neither PHPStan
 * nor Pint objects to the import left unused behind it.
 */
class SegmentPolicy
{
    /**
     * See the segments the campaign has named.
     *
     * Answered from ViewSupporters rather than from a segment permission of its
     * own: a segment is a saved narrowing of the supporter list, so an operator
     * who may read the list may read the ways it has been narrowed. The rule
     * itself holds postcode prefixes and nothing else (D-24), so this ability
     * gives away no fact about a person that ViewSupporters does not.
     */
    public function viewAny(User $operator): bool
    {
        return $operator->can(Permission::ViewSupporters->value);
    }

    /**
     * Name a new narrowing of the campaign's list.
     */
    public function create(User $operator): bool
    {
        return $operator->can(Permission::EditSupporters->value);
    }

    /**
     * Change a segment already named, including the postcodes it narrows to.
     *
     * The segment is not consulted, for the reason SupporterPolicy::update()
     * gives: campaign isolation here is physical, so segments live in the
     * campaign's own database and there is no campaign_id on the row to compare
     * against. An ownership check written here would have nothing to check and
     * would read as though it were protecting something.
     *
     * Authorship is not consulted either, and that is a decision rather than an
     * oversight. `segments.operator_id` is nullable and nulled on delete
     * precisely because a shared object outlives whoever created it, so a
     * policy that let only the author edit would refuse everybody the moment
     * that operator left the campaign.
     */
    public function update(User $operator, Segment $segment): bool
    {
        return $operator->can(Permission::EditSupporters->value);
    }

    /**
     * Remove a segment from the campaign.
     *
     * Answered from EditSupporters and deliberately **not** from
     * DeleteSupporters, which is the tempting reuse and would be the wrong
     * authority twice over. That permission is withheld from Staff because a
     * supporter row carries no soft delete and nothing to recover it from;
     * deleting a segment destroys no supporter and no blast, and the rule it
     * held is a name and a handful of prefixes that retype in seconds. Granting
     * it from DeleteSupporters would also make removing a segment harder than
     * emptying it -- an operator holding EditSupporters could already strip a
     * segment's prefixes down to a rule matching nobody.
     *
     * What a deletion does to a blast that used the segment is D-27's and is
     * not answered here; today nothing points at a segment at all.
     */
    public function delete(User $operator, Segment $segment): bool
    {
        return $operator->can(Permission::EditSupporters->value);
    }
}
