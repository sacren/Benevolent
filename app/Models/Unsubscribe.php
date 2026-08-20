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
 * nothing can come to read it as whether somebody is subscribed. **The blast
 * list reads this table as of Phase 5 Step 4 and does not change that**: it
 * counts withdrawals per blast and counts the ones attributed to no blast at
 * all, which are questions about events. Neither asks whether anybody is
 * subscribed, and neither could be answered from here.
 *
 * **The migration's "Nothing writes this table yet" stopped being true with
 * this model, and the correction lives here because migrations are frozen
 * once run.** The unsubscribe request is the table's one writer, and it writes
 * only when the request changes somebody's status -- a repeat of an act
 * already recorded is not a second withdrawal.
 *
 * **The migration's "Not indexed: nothing reads this table yet" is the second
 * sentence this model now corrects.** The blast list reads it, and the
 * measurement that sentence deferred has been taken: at 4,507 withdrawals over
 * 2.5M copies, a per-blast count costs 193.4 ms and no extra query, and adding
 * an index on `blast_recipient_id` changed the page by -3.2 ms. It is never
 * consulted, because PostgreSQL reads the whole of this table and looks each
 * row's copy up by primary key rather than searching this column. So the table
 * is still not indexed, now on evidence rather than for want of a reader, and
 * the question of whether it ever should be is D-51's at Step 5 -- decided by
 * how large this table grows, which is the quantity that moves (Blueprint
 * v0.28), and not by how many people a campaign writes to.
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
