<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlastRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One supporter's copy of one blast.
 *
 * Sits in app/Models/ while the module's vocabulary lives in app/Blasts/, the
 * split this application makes for Blast, Supporter and AuditEntry alike.
 *
 * **This model deliberately names no connection**, so it follows the default
 * one that tenancy has already switched onto the campaign serving the request
 * or running the job. Naming central here would pool every campaign's
 * recipients into one table -- which of everything in this application would be
 * the worst thing to pool, since these rows are the list of people a campaign
 * contacted.
 *
 * **It carries no `#[Fillable]`, and that is the point rather than an
 * omission.** Laravel guards every attribute by default, and nothing an
 * operator submits reaches this table at all: a row is written by the sending
 * path from a supporter's id, and updated by the same path with the result. A
 * fillable list here would describe a form that does not exist and should not.
 *
 * **Nothing about the person is stored (D-10).** There is no address, no name
 * and no postcode -- only `supporter_id`, nulled when a supporter is erased. So
 * a blast's record of who it reached is a set of keys into a table an erasure
 * genuinely empties, rather than a second copy of the same people that an
 * erasure would have to be taught about.
 *
 * @property int $id
 * @property int $blast_id
 * @property int|null $supporter_id
 * @property Carbon|null $sent_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * **No relations and no helpers, deliberately.** Nothing in the application
 * reads a recipient's blast or its supporter yet: the sending path claims by
 * key and the surfaces that report on a send count rows. A relation with no
 * reader is the shape this project keeps refusing -- the same reason `Blast`
 * carries no `operator()`. **Trigger:** the first surface that names an
 * individual person a blast reached, which also needs the `view` ability
 * `BlastPolicy` deliberately does not have.
 */
class BlastRecipient extends Model
{
    /** @use HasFactory<BlastRecipientFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
