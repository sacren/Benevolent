<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlastRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * and no postcode -- only `supporter_id`, nulled when a supporter is erased,
 * and `link_token`, which a database trigger removes in the same act. So a
 * blast's record of who it reached is a set of keys into a table an erasure
 * genuinely empties, rather than a second copy of the same people that an
 * erasure would have to be taught about. The token is a credential rather than
 * a fact about anybody, but it resolves to a person while it exists and is a
 * join key back to an address in copies outside the schema, which is why it
 * does not outlive the key beside it (D-49).
 *
 * @property int $id
 * @property int $blast_id
 * @property int|null $supporter_id
 * @property string|null $link_token
 * @property Carbon|null $sent_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * **One relation, and the trigger that asked for it has fired.** This model
 * carried none while nothing read a recipient's supporter, with the trigger
 * recorded as "the first surface that names an individual person a blast
 * reached". The unsubscribe page is it: somebody arrives holding one copy of
 * one message, and the page shows the address that copy went to, so the
 * message has to be able to say whose it is. `blast()` is still absent, and
 * for the original reason -- nothing reads it.
 *
 * **The relation is also the resolver's guard rather than a convenience.** A
 * recipient row outlives the person it named, so the lookup behind that page
 * requires this relation to *exist* before it will resolve a link (D-46);
 * after an erasure it does not, and the link names nobody.
 */
class BlastRecipient extends Model
{
    /** @use HasFactory<BlastRecipientFactory> */
    use HasFactory;

    /**
     * The person this copy of the message was written to, while they exist.
     *
     * Nullable on purpose rather than by accident: `supporter_id` is nulled
     * when a supporter is erased and the row stays, so this returns nothing
     * for a message whose reader is gone -- which is what an erasure is meant
     * to leave behind, and what the unsubscribe lookup depends on.
     *
     * @return BelongsTo<Supporter, $this>
     */
    public function supporter(): BelongsTo
    {
        return $this->belongsTo(Supporter::class);
    }

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
