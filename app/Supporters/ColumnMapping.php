<?php

declare(strict_types=1);

namespace App\Supporters;

use InvalidArgumentException;

/**
 * The operator's statement of which column in their file is which.
 *
 * Every field here is a header taken from the file itself, chosen by the person
 * who has the file, because **the importer never sniffs.** Nothing in this
 * class inspects a header's spelling to decide what it means; the headers were
 * shown to the operator and this is what they answered.
 *
 * Stored as JSON on the import record rather than as columns, because it is one
 * indivisible statement about one file, it is never queried by any part of it,
 * and its shape follows NameColumnMode's cases -- which columns are even
 * present depends on the mode the operator chose. Splitting it into columns
 * would make three of them meaningless in two of the three modes.
 */
readonly class ColumnMapping
{
    /**
     * @param  string  $email  the header holding the address, which is the identity (D-8)
     * @param  string|null  $name  the display-name header, when the mode is Single
     * @param  string|null  $givenName  the given-name header, when the mode is Split
     * @param  string|null  $familyName  the family-name header, when the mode is Split
     * @param  string|null  $postcode  the postcode header, if the file has one at all
     */
    public function __construct(
        public string $email,
        public NameColumnMode $nameMode,
        public ?string $name = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $postcode = null,
    ) {}

    /**
     * Rebuild a mapping from the record it was stored on.
     *
     * Deliberately strict rather than forgiving. A mapping that arrives without
     * an email column is not a mapping with a missing field -- it is a
     * statement that cannot be acted on, since the address is the identity and
     * there is nothing to match a row against without it. Reading it as an
     * empty string instead would import a whole file's worth of rows keyed on
     * nothing, which is the failure this refuses to reach.
     *
     * @param  array<array-key, mixed>  $mapping
     */
    public static function fromArray(array $mapping): self
    {
        $email = $mapping['email'] ?? null;
        $mode = $mapping['name_mode'] ?? null;

        if (! is_string($email) || $email === '') {
            throw new InvalidArgumentException('The mapping names no column for the email address.');
        }

        if (! is_string($mode) || (($mode = NameColumnMode::tryFrom($mode)) === null)) {
            throw new InvalidArgumentException('The mapping names no way of reading the name.');
        }

        return new self(
            email: $email,
            nameMode: $mode,
            name: self::optionalString($mapping['name'] ?? null),
            givenName: self::optionalString($mapping['given_name'] ?? null),
            familyName: self::optionalString($mapping['family_name'] ?? null),
            postcode: self::optionalString($mapping['postcode'] ?? null),
        );
    }

    /**
     * @return array{email: string, name_mode: string, name: string|null, given_name: string|null, family_name: string|null, postcode: string|null}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name_mode' => $this->nameMode->value,
            'name' => $this->name,
            'given_name' => $this->givenName,
            'family_name' => $this->familyName,
            'postcode' => $this->postcode,
        ];
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
