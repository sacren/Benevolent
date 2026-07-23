<?php

declare(strict_types=1);

namespace App\Http\Requests\Supporters;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Uploading a list, before anybody has said what is in it.
 *
 * Authority is not asked here. The controller asks the policy, so that the
 * ability checked and the ability performed are the same line of code; a
 * FormRequest::authorize() would be a second answer to the same question, free
 * to disagree with the first. The sibling supporter requests say the same.
 *
 * **The file is all this step collects, and that is the design rather than a
 * simplification.** What the columns mean is a statement about a file, and an
 * operator cannot make it before the file has been read -- asking them to type
 * header names from memory is how an import silently reads the wrong column for
 * every row. So the upload arrives first, its headers are read, and the mapping
 * is asked with those headers in front of them.
 */
class StoreSupporterImportRequest extends FormRequest
{
    /**
     * The largest file this will take.
     *
     * Stated by the application rather than left to whatever the environment's
     * PHP happens to allow, so the refusal is a validation message an operator
     * can read instead of a request that dies at the web server with no useful
     * answer. Twenty megabytes is on the order of two hundred thousand rows of
     * a typical export, which is far larger than any list this product has been
     * shown. Note the environment still has to permit at least this much --
     * upload_max_filesize and post_max_size are both 100M in development.
     */
    private const int MAX_KILOBYTES = 20480;

    /**
     * @return array<string, array<int, File|string>>
     */
    public function rules(): array
    {
        return [
            // csv and txt together, deliberately. A CSV's media type is not
            // reliably reported -- the same file is text/csv from one operating
            // system and text/plain from another, and application/vnd.ms-excel
            // from a machine with a spreadsheet installed -- so a rule naming
            // only csv refuses perfectly good files depending on who exported
            // them. What the file actually contains is settled by reading it,
            // which is the next thing that happens.
            'file' => ['required', File::types(['csv', 'txt'])->max(self::MAX_KILOBYTES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'list'];
    }
}
