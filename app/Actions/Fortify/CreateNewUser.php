<?php

namespace App\Actions\Fortify;

use App\Authorization\OperatorRole;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $operator = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Assigned after construction rather than passed in, because `role` is
        // not fillable: everything above came from the request, and a fillable
        // role would let anyone posting to this campaign's open /register grant
        // themselves governance of it.
        $operator->role = $this->roleForNewOperator();

        $operator->save();

        return $operator;
    }

    /**
     * The role a newly registered operator joins with.
     *
     * A campaign has to get its first Owner from somewhere. Provisioning does
     * not create one — `campaign:create` makes the database and the domain, not
     * an identity — so the first operator to register claims the campaign, and
     * everyone after joins as Staff for an Owner to promote.
     *
     * The count and the insert are not atomic, so two registrations racing on
     * an empty campaign would both see zero and both become Owner. Left alone
     * deliberately: the window is the width of one insert on a campaign that
     * has never been used, and the repair is for an Owner to demote the other.
     * Closing it properly means onboarding by invitation rather than open
     * registration, which is a Phase 1 product decision, not a lock.
     */
    private function roleForNewOperator(): OperatorRole
    {
        return User::query()->exists()
            ? OperatorRole::default()
            : OperatorRole::Owner;
    }
}
