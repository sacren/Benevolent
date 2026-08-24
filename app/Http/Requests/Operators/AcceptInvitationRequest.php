<?php

declare(strict_types=1);

namespace App\Http\Requests\Operators;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What somebody accepting an invitation chooses for themselves.
 *
 * **A name and a password, and deliberately not an address.** The address is
 * the invitation's (AcceptOperatorInvitation), so there is no field for a
 * request to substitute another one into -- which is the whole difference
 * between accepting an invitation and registering. Nor a role: nothing here
 * reads one, and a test posts one anyway to prove it.
 *
 * No authorize(): the credential in the URL is the authority, and the
 * controller has already refused a request that does not hold a live one.
 */
class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
