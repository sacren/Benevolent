<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Models\Segment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Stringable;

/**
 * Naming a narrowing of the campaign's list, or re-aiming one already named.
 *
 * **One request class for both actions, where the supporter module has two, and
 * the discriminator is the field set rather than the uniqueness rule.** The two
 * supporter requests differ twice over: the update rule has to ignore the
 * supporter being edited, *and* it carries a `subscription_status` field the
 * create form deliberately does not offer. Nothing differs here -- the same two
 * fields, validated the same way, whether the segment exists yet or not -- and
 * the ignore is one expression that is already correct for both, because
 * `route('segment')` is null on the way in and ignore(null) narrows nothing.
 * Two identical classes would be two places to change one rule, and the second
 * would be the one somebody forgot.
 *
 * **`Rule::unique` is sufficient here, and at Phase 1 it was not -- which is
 * Step 1's choice of index paying out.** D-8 needed a custom rule object
 * because the constraint it had to express was a unique index on
 * `lower(email)`: `Rule::unique('supporters', 'email')` compiles to
 * `where "email" = ?`, passes a case variant, and lets the insert reach the
 * expression index and come back as a 23505 in an operator's face. Step 1
 * deliberately gave `segments.name` a *plain* unique index instead, on the
 * ground that a duplicate segment name is visible and correctable where a
 * duplicate address is silent -- so the rule the framework ships compares
 * exactly what the index compares, and there is nothing here to hand-roll.
 *
 * Authority is not asked here. The controller asks the policy, so that the
 * ability checked and the ability performed are the same line of code; a
 * FormRequest::authorize() would be a second answer to the same question, free
 * to disagree with the first.
 */
class NameSegmentRequest extends FormRequest
{
    /**
     * Rule::unique() returns Illuminate\Validation\Rules\Unique, which
     * implements neither validation contract -- only Stringable, because the
     * builder exists to be rendered back into a `unique:...` rule string. So
     * the annotation names Stringable rather than ValidationRule, which is a
     * different answer from UpdateSupporterRequest's note about Rule::enum():
     * that one really does implement the older Rule contract.
     *
     * @return array<string, array<int, Stringable|ValidationRule|string>>
     */
    public function rules(): array
    {
        $segment = $this->route('segment');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('segments', 'name')
                    ->ignore($segment instanceof Segment ? $segment->getKey() : null),
            ],

            // The operator types postcodes the way they would say them --
            // "902, 021" -- so what arrives is one line rather than a list. It
            // is validated as the string it is and parsed by prefixes() below,
            // which keeps the error messages about the field the operator can
            // actually see: validating a parsed array would report errors
            // against `postcode_prefixes.2`, which names nothing on the page.
            //
            // **Required, where the same field on a blast is optional, and the
            // asymmetry is the schema's.** An empty rule on a blast is null,
            // which means "everyone this campaign may contact"; a segment that
            // narrows to nothing is not a segment, which is why the database
            // refuses a segment naming neither prefixes nor a district
            // (`segments_narrow_one_way_only`). Step 1 recorded that
            // checking this belongs to the form rather than to a check
            // constraint, because an empty rule is *safe* under the fail-closed
            // reading rather than dangerous -- it reaches nobody.
            'postcode_prefixes' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * The fields' names as the form labels them, for the framework's own
     * messages.
     *
     * The identifier stays `postcode_prefixes` (D-41) while the label says ZIP
     * codes, and `required` is the rule an operator meets first: submitting the
     * form empty used to answer "The postcode prefixes field is required." under
     * a field labelled "ZIP codes".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'postcode_prefixes' => 'ZIP codes',
        ];
    }

    /**
     * Refuse a line that parses to no postcodes at all.
     *
     * `required` catches an empty field; it does not catch a field holding only
     * separators, because `,,,` is a perfectly good non-empty string. That
     * parses to no prefixes, which the column would accept as `[]` and
     * App\Supporters\PostcodeNarrowing would answer with nobody -- so the
     * segment would exist, look like a rule, and match no one.
     *
     * **The form's notion of an unusable prefix and the matcher's cannot
     * disagree in the widening direction, and that is worth stating rather than
     * assuming.** prefixes() drops what trim() empties; the matcher drops what
     * the fold empties, and the fold removes only the space. Every input trim
     * empties, the fold empties too, so nothing the form keeps is something the
     * matcher throws away as blank. The one input that survives both is a
     * non-breaking space, which the fold has never removed -- a recorded
     * incompleteness rather than a new one -- and it produces a segment matching
     * nobody. That is the fail-closed direction.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->prefixes() === []) {
                    $validator->errors()->add(
                        'postcode_prefixes',
                        __('Name at least one ZIP code for this segment to narrow to.'),
                    );
                }
            },
        ];
    }

    /**
     * The segment as this form describes it, ready to be assigned.
     *
     * @return array{name: string, postcode_prefixes: list<string>}
     */
    public function named(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'postcode_prefixes' => $this->prefixes(),
        ];
    }

    /**
     * The postcode prefixes the operator named.
     *
     * Stored as the operator typed them, trimmed and no more. Folding happens at
     * match time against a column that is itself unfolded, so folding here as
     * well would throw away what the operator wrote for no gain -- and the page
     * would show them back a prefix they did not type.
     *
     * Reads the input rather than the validated set, because after() runs while
     * validation is still deciding and validated() would raise there.
     *
     * @return list<string>
     */
    private function prefixes(): array
    {
        $typed = $this->input('postcode_prefixes');

        if (! is_string($typed)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $typed)),
            static fn (string $prefix): bool => $prefix !== '',
        ));
    }
}
