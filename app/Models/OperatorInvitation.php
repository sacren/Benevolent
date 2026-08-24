<?php

declare(strict_types=1);

namespace App\Models;

use App\Authorization\OperatorRole;
use Database\Factories\OperatorInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One act of a campaign admitting somebody who is not yet an operator of it.
 *
 * The schema is D-54's and says almost everything worth saying; see the
 * migration that creates `operator_invitations`. This model adds the one thing
 * a schema cannot: which of its columns a request may fill.
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
#[Fillable(['email'])]
#[Hidden(['token'])]
class OperatorInvitation extends Model
{
    /** @use HasFactory<OperatorInvitationFactory> */
    use HasFactory;

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
