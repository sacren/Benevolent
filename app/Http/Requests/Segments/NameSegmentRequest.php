<?php

declare(strict_types=1);

namespace App\Http\Requests\Segments;

use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Segment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Number;
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
 * create form deliberately does not offer. Here the fields are the same on both
 * actions and validated the same way, and the two differences are each one
 * expression that is already correct for both: the ignore, because
 * `route('segment')` is null on the way in and ignore(null) narrows nothing,
 * and the kind (below), which a new segment reads from `narrow_by` and an
 * existing one from itself. Two near-identical classes would be two places to
 * change one rule, and the second would be the one somebody forgot.
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
 * **A segment narrows by ZIP code prefixes or by one congressional district
 * (D-37), chosen when it is named and not changed by re-aiming it.** A new
 * segment says which in `narrow_by`, and one already named keeps its kind: a
 * district segment's form edits its district and a prefix segment's edits its
 * prefixes, whatever else is posted.
 *
 * **The reason it is fixed has changed, because the first one expired.** It was
 * that a blast could be aimed at a prefix segment and not at a district one,
 * so turning the first into the second would re-aim every draft pointing at it
 * into an aim the sending path answered with nobody. D-38 is decided and the
 * sending path now resolves a district segment, so that sentence is no longer
 * true. What survives is a promise rather than a failure: a prefix is a postal
 * question that claims nothing about anybody's representation, and a district
 * is a claim that each person reached is a constituent, so switching a named
 * segment between them would change what every draft aimed at it asserts --
 * silently, under a name the operator chose for the other meaning. The blast
 * list's "has changed since" comparison says the same thing from its own side:
 * it can report a rule that moved, and has no sentence for a rule that changed
 * kind.
 *
 * Authority is not asked here. The controller asks the policy, so that the
 * ability checked and the ability performed are the same line of code; a
 * FormRequest::authorize() would be a second answer to the same question, free
 * to disagree with the first.
 */
class NameSegmentRequest extends FormRequest
{
    public const string BY_POSTCODES = 'postcodes';

    public const string BY_DISTRICT = 'district';

    private ?ZctaDistricts $relation = null;

    /**
     * Rule::unique() returns Illuminate\Validation\Rules\Unique, which
     * implements neither validation contract -- only Stringable, because the
     * builder exists to be rendered back into a `unique:...` rule string. So
     * the annotation names Stringable rather than ValidationRule, which is a
     * different answer from UpdateSupporterRequest's note about Rule::enum():
     * that one really does implement the older Rule contract. Rule::requiredIf()
     * and Rule::in() are Stringable for the same reason, and a first-class
     * closure is a Closure, as ComposeBlastRequest records for its own.
     *
     * @return array<string, array<int, Closure|Stringable|ValidationRule|string>>
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
            // **Required of a segment narrowing by ZIP codes, where the same
            // field on a blast is optional, and the asymmetry is the
            // schema's.** An empty rule on a blast is null,
            // which means "everyone this campaign may contact"; a segment that
            // narrows to nothing is not a segment, which is why the database
            // refuses a segment naming neither prefixes nor a district
            // (`segments_narrow_one_way_only`). Step 1 recorded that
            // checking this belongs to the form rather than to a check
            // constraint, because an empty rule is *safe* under the fail-closed
            // reading rather than dangerous -- it reaches nobody.
            'postcode_prefixes' => [
                Rule::requiredIf(fn (): bool => $this->kind() === self::BY_POSTCODES),
                'nullable',
                'string',
                'max:1000',
            ],

            // Only read when a new segment is named; one already named keeps
            // its kind, so what arrives here on an edit is ignored.
            'narrow_by' => ['nullable', Rule::in([self::BY_POSTCODES, self::BY_DISTRICT])],

            // A seat as people write it -- `MA-07`, `ma-7`, `AK-AL` -- checked
            // against the relation this release ships, so a seat it does not
            // name is refused here rather than stored and read as nobody.
            'district' => [
                Rule::requiredIf(fn (): bool => $this->kind() === self::BY_DISTRICT),
                'nullable',
                'string',
                'max:20',
                $this->namedDistrict(...),
            ],
        ];
    }

    /**
     * Refuse a district the relation this release ships does not name.
     *
     * The message names the Congress, because "not a district" is true only of
     * one map: a later Congress's map can add a seat this one does not have,
     * or drop one it does (D-43).
     */
    private function namedDistrict(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->kind() !== self::BY_DISTRICT || ! is_string($value) || $this->seat() !== null) {
            return;
        }

        $fail(__("That is not a district in the :congress Congress's map. Write a state and a district number, like MA-07, or AL for a state's only seat, like AK-AL.", [
            'congress' => Number::ordinal($this->relation()->congress()),
        ]));
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
            'district' => 'district',
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
     * **It says nothing when `required` already has.** An empty form used to
     * answer with two messages for one field -- "The ZIP codes field is
     * required." and this one -- because an after-hook runs whether or not a
     * rule has failed. The page showed the first; the second was noise
     * recorded at Step 4 and settled here, where this hook had to learn about
     * district segments anyway.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->kind() !== self::BY_POSTCODES || $validator->errors()->has('postcode_prefixes')) {
                    return;
                }

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
     * **Both rules every time, one of them null**, so that the pair always
     * satisfies `segments_narrow_one_way_only` -- the same reason
     * ComposeBlastRequest::composed() returns both halves of a blast's aim.
     * The district is stored as the seat's own label, so `ma-7` is kept as
     * `MA-07`: the operator's spelling of a seat carries nothing the label
     * does not, unlike a ZIP code prefix, which is stored as typed.
     *
     * @return array{name: string, postcode_prefixes: list<string>|null, district: string|null}
     */
    public function named(): array
    {
        $byDistrict = $this->kind() === self::BY_DISTRICT;

        return [
            'name' => (string) $this->validated('name'),
            'postcode_prefixes' => $byDistrict ? null : $this->prefixes(),
            'district' => $byDistrict ? $this->seat()?->label() : null,
        ];
    }

    /**
     * Which rule this form is naming: a new segment's `narrow_by`, ZIP codes
     * when it says nothing -- the only kind there was before D-37 -- or the
     * kind of the segment being edited, whatever the form says.
     */
    private function kind(): string
    {
        $segment = $this->route('segment');

        if ($segment instanceof Segment) {
            return $segment->district === null ? self::BY_POSTCODES : self::BY_DISTRICT;
        }

        return $this->input('narrow_by') === self::BY_DISTRICT ? self::BY_DISTRICT : self::BY_POSTCODES;
    }

    /**
     * The district the operator typed, as the relation names it, or null.
     */
    private function seat(): ?Seat
    {
        $typed = $this->input('district');

        return is_string($typed) ? Seat::parse($typed, $this->relation()) : null;
    }

    /**
     * The relation this release ships, read at most once per request and only
     * by a form naming a district: reading it costs about 14 ms and 11 MB that
     * a segment of ZIP codes has no use for.
     */
    private function relation(): ZctaDistricts
    {
        return $this->relation ??= ZctaDistricts::shipped();
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
