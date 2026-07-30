<?php

declare(strict_types=1);

namespace App\Http\Requests\Blasts;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing a blast, or changing one still in draft.
 *
 * **One request class for both actions, where the supporter module has two.**
 * Those two differ for a real reason -- the update rule has to ignore the
 * supporter being edited when it checks the address is unused -- and nothing
 * here differs at all: the same three fields, validated the same way, whether
 * the blast exists yet or not. Two identical classes would be two places to
 * change one rule, and the second would be the one somebody forgot.
 *
 * Authority is not asked here. The controller asks the policy, so that the
 * ability checked and the ability performed are the same line of code; a
 * FormRequest::authorize() would be a second answer to the same question, free
 * to disagree with the first.
 */
class ComposeBlastRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],

            // No maximum, matching the `text` column. A campaign that has
            // written a long message has written a long message, and refusing
            // it at some invented number would be this application having an
            // opinion about the campaign's own words.
            'body' => ['required', 'string'],

            // The operator types postcodes the way they would say them --
            // "M15, EH8" -- so what arrives is one line rather than a list.
            // It is validated as the string it is and parsed by prefixes()
            // below, which keeps the error messages about the field the
            // operator can actually see: validating a parsed array would report
            // errors against `postcode_prefixes.2`, which names nothing on the
            // page.
            'postcode_prefixes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The blast as this form describes it, ready to be assigned.
     *
     * @return array{subject: string, body: string, postcode_prefixes: list<string>|null}
     */
    public function composed(): array
    {
        return [
            'subject' => (string) $this->validated('subject'),
            'body' => (string) $this->validated('body'),
            'postcode_prefixes' => $this->prefixes(),
        ];
    }

    /**
     * The postcode prefixes the operator named, or null if they named none.
     *
     * **Null rather than an empty list, and the difference is not cosmetic.**
     * Null is the column's own way of saying "every supporter this campaign may
     * contact"; an empty list is an aim that names nothing, which
     * App\Blasts\BlastAudience answers with nobody. An operator who clears the
     * field means the first, so that is what gets stored -- and a blank entry
     * inside a list of real ones is dropped rather than allowed to widen or
     * narrow anything.
     *
     * The prefixes are stored as the operator typed them, trimmed and no more.
     * Folding happens at match time against a column that is itself unfolded,
     * so folding here as well would throw away what the operator wrote for no
     * gain -- and the page would show them back a prefix they did not type.
     *
     * @return list<string>|null
     */
    private function prefixes(): ?array
    {
        $typed = $this->validated('postcode_prefixes');

        if (! is_string($typed)) {
            return null;
        }

        $prefixes = array_values(array_filter(
            array_map(trim(...), explode(',', $typed)),
            static fn (string $prefix): bool => $prefix !== '',
        ));

        return $prefixes === [] ? null : $prefixes;
    }
}
