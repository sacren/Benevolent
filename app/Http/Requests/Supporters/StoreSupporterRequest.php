<?php

declare(strict_types=1);

namespace App\Http\Requests\Supporters;

use App\Supporters\UniqueSupporterEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding somebody to the campaign's list by hand.
 *
 * Authority is not asked here. The controller asks the policy, so that the
 * ability checked and the ability performed are the same line of code; a
 * FormRequest::authorize() would be a second answer to the same question, free
 * to disagree with the first.
 *
 * **The name fields are all optional, including `name`, and that follows the
 * schema rather than relaxing it.** A row with an address and no name is a real
 * supporter -- a petition widget that asked only for an email produces one --
 * so requiring a name here would refuse a person the campaign can perfectly
 * well contact. The address is the only thing required, because it is the
 * identity (D-8) and this module carries no second channel.
 *
 * The given and family fields are offered separately because an operator who
 * has just spoken to someone knows where the boundary falls, and that is
 * information nothing can recover later. An operator who does not know leaves
 * them blank, and nothing is invented -- which is the same rule the importer
 * follows and the reason the schema has three columns rather than one.
 *
 * Subscription status is deliberately absent. Somebody an operator is typing in
 * by hand is somebody they have just dealt with, so the default -- contactable
 * -- is the answer, and the edit form is where that changes. Offering the
 * choice at the moment of adding invites a campaign to record a consent state
 * this application is not the system of record for.
 */
class StoreSupporterRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'given_name' => ['nullable', 'string', 'max:255'],
            'family_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueSupporterEmail],

            // No format rule and no country. A postcode is stored exactly as
            // given (D-8's sibling decision), because validating one correctly
            // means validating it for everywhere, and a rule that is right for
            // one country silently refuses supporters from everywhere else.
            'postcode' => ['nullable', 'string', 'max:255'],
        ];
    }
}
