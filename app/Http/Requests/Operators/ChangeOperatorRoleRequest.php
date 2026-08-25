<?php

declare(strict_types=1);

namespace App\Http\Requests\Operators;

use App\Authorization\OperatorRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The authority an Owner is giving somebody on the roster.
 *
 * Authority over the act is OperatorPolicy's, asked in the controller for the
 * reason every controller here asks there. This class says only what a valid
 * role looks like; ChangeOperatorRole is the one place that reads it, and
 * nothing else a request carries is read at all.
 */
class ChangeOperatorRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(OperatorRole::class)],
        ];
    }
}
