<?php

declare(strict_types=1);

namespace App\Supporters;

use App\Models\Supporter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses an address the campaign already has a supporter for.
 *
 * **This exists because Laravel's own `Rule::unique` cannot express the
 * constraint, which was measured three ways rather than assumed.** D-8 put the
 * uniqueness on `lower(email)`, and:
 *
 * - `Rule::unique('supporters', 'email')` compiles to `where "email" = ?`, so
 *   it reports a case variant as available. The validator then passes, the
 *   insert reaches the index, and PostgreSQL refuses it with SQLSTATE 23505 --
 *   which is a 500 in an operator's face rather than "this person is already on
 *   your list", on the single most common variation in real data.
 * - `Rule::unique('supporters', DB::raw('lower(email)'))` throws outright:
 *   `Object of class Illuminate\Database\Query\Expression could not be
 *   converted to string`. The rule stringifies its column.
 * - `Rule::unique(...)->where(fn ($q) => $q->whereRaw('lower(email) = ?', ...))`
 *   also passes, because the closure is ANDed with the plain column comparison
 *   rather than replacing it, so the base condition still matches nothing.
 *
 * So the lookup goes through the model's own scope, which folds both sides and
 * is the one place that knows how the index matches.
 *
 * Ignoring a supporter is not an optional refinement: without it, editing
 * somebody without touching their address would refuse the edit on the grounds
 * that they already exist, which is true and useless.
 */
readonly class UniqueSupporterEmail implements ValidationRule
{
    /**
     * @param  Supporter|null  $ignoring  the supporter being edited, if any
     */
    public function __construct(private ?Supporter $ignoring = null) {}

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            // Not this rule's question. A non-string reaches here only when the
            // `string` rule alongside it has already failed, and answering it
            // twice would put two messages on one field.
            return;
        }

        $taken = Supporter::query()
            ->whereEmailMatches($value)
            ->when(
                $this->ignoring !== null,
                fn ($query) => $query->whereKeyNot($this->ignoring->getKey()),
            )
            ->exists();

        if ($taken) {
            $fail('This campaign already has a supporter with that email address.');
        }
    }
}
