<?php

declare(strict_types=1);

namespace App\Http\Requests\Supporters;

use App\Models\Supporter;
use App\Supporters\SubscriptionStatus;
use App\Supporters\UniqueSupporterEmail;
use Illuminate\Contracts\Validation\Rule as LegacyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting somebody already on the campaign's list.
 *
 * Two things separate this from the store request, and both are deliberate
 * rather than incidental.
 *
 * The uniqueness rule ignores the supporter being edited. Without that, saving
 * a change of postcode would be refused because the address already belongs to
 * a supporter -- namely this one.
 *
 * And subscription status is editable here where it is not on the create form.
 * This is where a campaign records that somebody asked not to be contacted, and
 * it is the reason removal is the exceptional act rather than the ordinary one:
 * unsubscribing keeps the record that stops a later import putting them back,
 * while deleting them loses it.
 */
class UpdateSupporterRequest extends FormRequest
{
    /**
     * Rule::enum() returns Illuminate\Validation\Rules\Enum, which implements
     * the older Rule contract rather than ValidationRule, so the annotation has
     * to name both rather than the one that reads more tidily.
     *
     * @return array<string, array<int, LegacyRule|ValidationRule|string>>
     */
    public function rules(): array
    {
        $supporter = $this->route('supporter');

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'given_name' => ['nullable', 'string', 'max:255'],
            'family_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueSupporterEmail($supporter instanceof Supporter ? $supporter : null),
            ],
            'postcode' => ['nullable', 'string', 'max:255'],
            'subscription_status' => ['required', Rule::enum(SubscriptionStatus::class)],
        ];
    }
}
