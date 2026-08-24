<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Fold the address the way Fortify folds every address it is handed (D-61).
     *
     * `fortify.lowercase_usernames` makes Fortify lowercase whatever is typed at
     * sign-in, at registration and when a reset link is requested, and then
     * compare it exactly against `users.email`. So a stored address is only
     * ever reachable in its lowercase form: measured, an operator stored as
     * `Jean@Probe.test` could not sign in even typing exactly that, while one
     * stored as `jean@probe.test` signed in typing `Jean@Probe.test`. Fortify's
     * own profile controller folds for that reason; this application replaced
     * it with its own and did not, so an operator who added a capital to their
     * address here locked themselves out of password sign-in.
     *
     * Folded before validation rather than after, so the uniqueness rule is
     * asked about the value that will actually be stored -- and so resubmitting
     * one's own address in another casing is recognised as no change at all,
     * rather than as a new address that clears the verification beside it.
     */
    protected function prepareForValidation(): void
    {
        if (config('fortify.lowercase_usernames') && $this->has(Fortify::username())) {
            $this->merge([
                Fortify::username() => Str::lower((string) $this->input(Fortify::username())),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
