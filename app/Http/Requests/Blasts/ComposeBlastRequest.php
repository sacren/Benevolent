<?php

declare(strict_types=1);

namespace App\Http\Requests\Blasts;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Stringable;

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
     * Two of the rule shapes here implement neither validation contract, and
     * the annotation names all three rather than the one that reads tidily.
     *
     * Rule::exists() returns Illuminate\Validation\Rules\Exists, which is only
     * Stringable -- the builder exists to be rendered back into an
     * `exists:...` string, the same answer NameSegmentRequest records for
     * Rule::unique(). And a first-class closure is a Closure, which the
     * validator accepts as a rule and which no contract covers.
     *
     * @return array<string, array<int, Closure|Stringable|ValidationRule|string>>
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

            // The segment this blast is aimed at, if the campaign has named
            // one it would rather point at than retype (D-26).
            //
            // **`exists` is what keeps a campaign inside its own campaign, and
            // it does so without saying anything about tenancy.** The rule
            // compiles to a query on the default connection, which tenancy has
            // already switched onto the campaign serving the request, so a
            // segment id belonging to another campaign is simply not there. Ids
            // restart at 1 in every campaign (L-27), so this is the one
            // validation rule on this form whose absence would be a
            // cross-campaign leak rather than a bad row.
            'segment_id' => [
                'nullable',
                'integer',
                Rule::exists('segments', 'id'),
                $this->aimedOneWayOnly(...),
            ],

            // The operator types postcodes the way they would say them --
            // "902, 021" -- so what arrives is one line rather than a list.
            // It is validated as the string it is and parsed by prefixes()
            // below, which keeps the error messages about the field the
            // operator can actually see: validating a parsed array would report
            // errors against `postcode_prefixes.2`, which names nothing on the
            // page.
            'postcode_prefixes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Refuse a form that names a segment and types postcodes as well.
     *
     * **The database already forbids this row, so what this adds is a sentence
     * rather than a safety.** `blasts_aimed_one_way_only` would refuse the
     * write with SQLSTATE 23514 and the operator would see a 500; here they see
     * which of the two they have to give up. The constraint stays the thing
     * that makes it impossible, and this stays the thing that explains it --
     * the same division `UniqueSupporterEmail` and the `lower(email)` index
     * already make one module along.
     *
     * **It asks prefixes() rather than reading the raw field**, so the form's
     * idea of "the operator typed postcodes" and the storage's cannot disagree.
     * A comma with nothing either side of it parses to no prefixes at all, and
     * a rule written against the raw string would refuse a segment on the
     * strength of a stray comma.
     */
    private function aimedOneWayOnly(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->prefixes() !== null) {
            $fail(__('A blast is aimed one way. Choose a segment or type postcodes, not both.'));
        }
    }

    /**
     * The blast as this form describes it, ready to be assigned.
     *
     * Both halves of the aim are returned, and the pair is always consistent
     * because the rule above has already refused the form that names two. An
     * operator who switches from postcodes to a segment gets the postcodes
     * cleared, which is what makes switching possible at all -- returning only
     * the field that was filled in would leave the old aim behind and produce
     * exactly the row the check constraint refuses.
     *
     * @return array{subject: string, body: string, segment_id: int|null, postcode_prefixes: list<string>|null}
     */
    public function composed(): array
    {
        return [
            'subject' => (string) $this->validated('subject'),
            'body' => (string) $this->validated('body'),
            'segment_id' => $this->segmentId(),
            'postcode_prefixes' => $this->prefixes(),
        ];
    }

    /**
     * The segment the operator aimed at, or null if they aimed by postcode or
     * at everybody.
     *
     * A `<select>` with no selection submits an empty string rather than
     * nothing at all, which `nullable` treats as null on the way through
     * validation but which arrives here as `''` if read raw. Read through
     * validated() and cast, so the empty choice and the absent field are the
     * same answer.
     */
    private function segmentId(): ?int
    {
        $named = $this->validated('segment_id');

        return is_numeric($named) ? (int) $named : null;
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
