<?php

declare(strict_types=1);

namespace App\Models;

use App\Supporters\ColumnMapping;
use App\Supporters\ImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One attempt at getting an existing list into this campaign.
 *
 * **Names no connection, for the same reason Supporter does not.** It follows
 * the default connection, which tenancy has already switched onto the campaign
 * serving the request -- so a campaign's imports, and the counts of what they
 * did to its list, live in that campaign's own database. Naming central here
 * would pool every campaign's uploads into one table.
 *
 * There is no policy on this model and that is deliberate. Authority over an
 * import is authority over the supporters it writes, so it is SupporterPolicy's
 * question to answer; a policy here would be a second answer to one question,
 * free to disagree with the first. Nothing asks it yet -- this commit ships the
 * reading and the record, and the surface an operator reaches them through
 * arrives with the ability that governs it.
 *
 * @property int $id
 * @property int|null $operator_id
 * @property string $original_filename
 * @property string $stored_path
 * @property list<string> $headers
 * @property array<string, mixed>|null $mapping
 * @property ImportStatus $status
 * @property int $rows_read
 * @property int $supporters_added
 * @property int $supporters_updated
 * @property int $rows_skipped
 * @property string|null $failure_reason
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['operator_id', 'original_filename', 'stored_path', 'headers'])]
class SupporterImport extends Model
{
    /**
     * What the operator said their file's columns mean, or nothing if they have
     * not said yet.
     *
     * The one place the stored JSON becomes the value object, so that every
     * reader gets the same strictness about what a usable mapping is rather
     * than each poking at array keys.
     */
    public function columnMapping(): ?ColumnMapping
    {
        if (! is_array($this->mapping)) {
            return null;
        }

        try {
            return ColumnMapping::fromArray($this->mapping);
        } catch (InvalidArgumentException) {
            // Stored, but not a statement anything can act on. Treated as
            // unmapped rather than thrown from an accessor, so the surfaces
            // reading this show "not mapped yet" instead of a 500.
            return null;
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'status' => ImportStatus::class,
            'finished_at' => 'datetime',
        ];
    }
}
