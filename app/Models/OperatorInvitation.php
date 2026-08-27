<?php

declare(strict_types=1);

namespace App\Models;

use App\Authorization\OperatorRole;
use App\Operators\OperatorInvitationPolicy;
use Carbon\CarbonInterface;
use Database\Factories\OperatorInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One act of a campaign admitting somebody who is not yet an operator of it.
 *
 * The schema is D-54's and says almost everything worth saying; see the
 * migration that creates `operator_invitations`. This model adds the two things
 * a schema cannot: which of its columns a request may fill, and how long an
 * invitation's link may be used (D-59), which is a comparison against the time
 * of reading and so no column, index or constraint can hold it.
 *
 * **Only the address is fillable, and the reason is the one `User` gives for
 * `role`.** An invitation's `role` is the authority it will grant, so a
 * mass-assigned role would let whatever reached a create call decide how much
 * governance somebody walks in with. Every writer names it deliberately, after
 * construction. The inviter columns are the same: they record who did this,
 * which is not a request's to say.
 *
 * **The token is hidden, because it is a credential.** It is the whole of what
 * authorizes a join (D-53 Axis 1 (i)), so a model serialized into a page, a log
 * line or a JSON response must not carry it by accident. The one page that
 * needs it -- the invitee's own -- already has it in its URL.
 *
 * @property int $id
 * @property string $email
 * @property OperatorRole $role
 * @property string|null $token
 * @property int|null $invited_by_id
 * @property string|null $invited_by_label
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Named here rather than discovered: OperatorInvitationPolicy is filed with its
// module, where path guessing will not reach it, so this line is the only
// wiring there is and deleting it turns the allow tests red.
#[UsePolicy(OperatorInvitationPolicy::class)]
#[Fillable(['email'])]
#[Hidden(['token'])]
class OperatorInvitation extends Model
{
    /** @use HasFactory<OperatorInvitationFactory> */
    use HasFactory;

    /**
     * How long an invitation's link may be used, counted from when it was sent
     * (D-59).
     *
     * **A comparison against `created_at` at read, never a column and never a
     * schedule** -- D-54 decided the schema owes nothing either way, and a
     * pruning command would be a fifth declaration nothing runs (deferral 17).
     * Deriving it also means changing this number changes the invitations
     * already in flight, which is the direction a credential should fail in.
     *
     * Seven days, because the credential is somebody's authority over this
     * campaign sitting in a mailbox -- and, under a `log` mailer (which
     * `.env.example` shipped until Phase 7 Step 2), in the application log,
     * where a live invitation's link opens exactly as it does from the inbox
     * (measured). The cost of too short is one withdrawal and one fresh
     * invitation, which the inviting writer now performs itself.
     */
    public const int LIFETIME_DAYS = 7;

    /**
     * Invitations whose link still works: unused, not withdrawn, and sent
     * within the lifetime.
     *
     * **The one definition of "live", so the places that ask cannot disagree.**
     * Phase 6 Step 5 measured what happens when they do: a lifetime applied to
     * the link alone left the invitee refused while the inviting writer told
     * the Owner the address "already has an invitation that has not been
     * used" and the partial index refused a fresh one at 23505. The index
     * itself cannot take part -- a time is not an immutable predicate -- so
     * InviteOperator withdraws an expired invitation before it writes another,
     * which is what brings the index into agreement.
     *
     * @param  Builder<OperatorInvitation>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereNotNull('token')->where('created_at', '>', self::lifetimeCutoff());
    }

    /**
     * Invitations nobody used or withdrew, whose lifetime has run out: they
     * still hold a credential, and it no longer opens anything.
     *
     * @param  Builder<OperatorInvitation>  $query
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('token')->where('created_at', '<=', self::lifetimeCutoff());
    }

    /**
     * Resolve `{invitation:token}` to a live invitation only.
     *
     * **The link is where an expired invitation is refused**, and refusing it
     * in the binding means the refusal is the one a spent link already gets:
     * the router answers 404 before the page or the form request runs, and
     * nothing is written -- a GET that acted would let a mail scanner's
     * prefetch withdraw an invitation (D-16(b)). Bound by id, as the Owner's
     * withdrawal is, an expired invitation is still found: taking it back is
     * still the campaign's to do.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== 'token') {
            return parent::resolveRouteBinding($value, $field);
        }

        return static::query()->live()->where('token', $value)->first();
    }

    /**
     * The moment before which an invitation has expired.
     */
    public static function lifetimeCutoff(): CarbonInterface
    {
        return now()->subDays(self::LIFETIME_DAYS);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OperatorRole::class,
            'accepted_at' => 'datetime',
        ];
    }
}
