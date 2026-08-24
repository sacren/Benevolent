<?php

declare(strict_types=1);

namespace App\Http\Requests\Operators;

use App\Authorization\OperatorRole;
use App\Operators\InviteOperator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Who a campaign is inviting, and with what authority.
 *
 * Authority over the act itself is OperatorInvitationPolicy's, asked in the
 * controller for the reason every other controller here asks there: the
 * ability a route checks and the ability its action performs cannot drift
 * apart. This class says only what a valid invitation looks like.
 */
class InviteOperatorRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // The writer's own question, asked once for every path that
                // invites -- see InviteOperator::refusalFor().
                function (string $attribute, mixed $value, Closure $fail): void {
                    $refusal = InviteOperator::refusalFor((string) $value);

                    if ($refusal !== null) {
                        $fail($refusal);
                    }
                },
            ],
            'role' => ['required', Rule::enum(OperatorRole::class)],
        ];
    }
}
