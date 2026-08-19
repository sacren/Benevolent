<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One withdrawal the product saw happen: somebody used the link in a message
 * to stop the campaign writing to them (D-45, D-47).
 *
 * **An event, never a state.** `supporters.subscription_status` stays the one
 * answer to "may this person be written to", and it is what `BlastAudience`
 * reads. The two disagree in both directions without either being wrong, which
 * the migration explains; this model has no supporter key and no status, so
 * nothing can come to read it as whether somebody is subscribed.
 *
 * **The migration's "Nothing writes this table yet" stopped being true with
 * this model, and the correction lives here because migrations are frozen
 * once run.** The unsubscribe request is the table's one writer, and it writes
 * only when the request changes somebody's status -- a repeat of an act
 * already recorded is not a second withdrawal.
 *
 * **A row names the copy of the message its link came from, or nothing at
 * all.** A message sent since per-recipient links carries its own token and
 * names itself; one sent before carries the supporter's token, which names a
 * person and no message, so its withdrawal is recorded with
 * `blast_recipient_id` null -- the true statement that the link could not say
 * which message it came from. It is never filled in with the latest blast,
 * which would tell a campaign a message did something it may not have done
 * (D-46).
 *
 * **This model deliberately names no connection**, so a campaign's withdrawals
 * land in the campaign's own database, for the reason `AuditEntry` gives.
 * No factory, as `AuditEntry` has none: every row a test needs is written by
 * the request that writes it in production, or straight to the table.
 *
 * @property int $id
 * @property int|null $blast_recipient_id
 * @property Carbon $created_at
 */
#[Fillable(['blast_recipient_id'])]
class Unsubscribe extends Model
{
    /**
     * A withdrawal is a statement about a moment, so it is written once and
     * never revised, and the table has no updated_at to keep.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
