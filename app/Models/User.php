<?php

namespace App\Models;

use App\Audit\OperatorAuditObserver;
use App\Authorization\OperatorRole;
use App\Operators\OperatorPolicy;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property OperatorRole $role
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Named here rather than discovered by convention, so that the wiring is a line
// someone can read, grep for, and delete to watch the audit tests go red. An
// observer that is written but never attached records nothing at all, and every
// test asserting a change was *not* recorded passes perfectly against it -- so
// the attachment is the kind of thing that has to be visible and asserted
// rather than assumed.
#[ObservedBy(OperatorAuditObserver::class)]
// Named here rather than discovered, for the reason OperatorInvitation gives:
// OperatorPolicy is filed with its module, so this line is the only wiring
// there is, and deleting it turns the roster's allow tests red.
#[UsePolicy(OperatorPolicy::class)]
#[Fillable(['name', 'email', 'password'])]
// `role` is hidden alongside the credentials, and for a different reason worth
// stating. It is not a secret -- an operator may perfectly well be told what
// they are -- but every consumer that can read it is one that can branch on it,
// and branching on a role rather than on a resolved permission is the mistake
// this application has refused three times over now (the policy, the gate
// registry, and the shared props beside it). Withholding it makes
// `$page.props.auth.user.role` undefined rather than merely inadvisable, which
// is the difference between a convention and a constraint.
#[Hidden(['password', 'role', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
// **MustVerifyEmail is what makes the `verified` middleware refuse anybody (D-60).**
// Without it, EnsureEmailIsVerified passes every authenticated operator
// through, and Phase 6 Step 1 measured exactly that: every campaign route sat
// behind a check that refused nobody. An operator's address is verified the
// moment they accept the invitation that reached it (AcceptOperatorInvitation),
// so arriving costs no second mail; what this contract enforces is the other
// case -- an operator who changes their address on the profile page is refused
// the campaign until they prove the new one, since that is where a password
// reset would be sent.
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => OperatorRole::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
