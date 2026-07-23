<?php

declare(strict_types=1);

namespace App\Http\Requests\Supporters;

use App\Models\SupporterImport;
use App\Supporters\NameColumnMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Stringable;

/**
 * The operator saying which column in their file is which.
 *
 * **Every column here is validated against the file's own headers**, which is
 * what makes this a choice rather than a guess. A mapping naming a column the
 * file does not contain would import that field as blank for every row and
 * report a clean run -- the worst kind of failure this module can have, because
 * it looks exactly like success.
 *
 * The name columns are required conditionally on the mode, and the modes are
 * the three honest shapes a real file has: one name column, two, or none. There
 * is deliberately no fourth option that lets the importer work it out.
 */
class StartSupporterImportRequest extends FormRequest
{
    /**
     * Rule::in() returns Illuminate\Validation\Rules\In and Rule::enum()
     * returns Illuminate\Validation\Rules\Enum; the only contract both
     * implement is Stringable, so that is what the annotation can honestly
     * name. The sibling requests name the validation contracts instead because
     * that is what their rules actually implement.
     *
     * @return array<string, array<int, Stringable|string>>
     */
    public function rules(): array
    {
        $import = $this->route('import');
        $headers = $import instanceof SupporterImport ? $import->headers : [];

        // The address is the identity (D-8), so it is the one column with no
        // "none" option: a file with no address column names nobody.
        $isAHeader = ['required', 'string', Rule::in($headers)];
        $optionalHeader = ['nullable', 'string', Rule::in($headers)];

        return [
            'email' => $isAHeader,
            'name_mode' => ['required', Rule::enum(NameColumnMode::class)],

            // required_if rather than required, because which name columns must
            // be named is exactly what the mode decides -- and a mode of `none`
            // must be able to name none of them without the form refusing.
            'name' => [...$optionalHeader, 'required_if:name_mode,'.NameColumnMode::Single->value],
            'given_name' => [...$optionalHeader, 'required_if:name_mode,'.NameColumnMode::Split->value],
            'family_name' => [...$optionalHeader, 'required_if:name_mode,'.NameColumnMode::Split->value],

            'postcode' => $optionalHeader,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'email column',
            'name_mode' => 'name format',
            'name' => 'name column',
            'given_name' => 'given name column',
            'family_name' => 'family name column',
            'postcode' => 'postcode column',
        ];
    }
}
