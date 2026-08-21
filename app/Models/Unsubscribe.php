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
 * an index on `blast_recipient_id` changed the page by -3.2 ms.
 *
 * **D-51 answers it at Step 5: still no index, and the reason written here at
 * Step 4 was too strong.** That paragraph said the column "is never consulted".
 * Re-measured at 1,000,000 copies with the same 4,507 withdrawals, it is: with
 * an index present PostgreSQL *does* choose it, replacing a sequential read of
 * this table with an index-only scan. It buys nothing -- 1,090.5 ms without,
 * 1,098.9 ms with -- because the cost of the per-blast count is the ten probes
 * into `blast_recipients` by primary key at 107.8 ms apiece, not the read of
 * this table at 0.7 ms. So the verdict is firmer than its old reason and the
 * reason has changed: not never consulted, but consulted and worth nothing.
 *
 * **What this table grows with, which is the quantity D-51 had to name
 * (Blueprint v0.28) and which the plan had wrong.** Both the plan and this
 * module's own decision entry said outcome rows grow with recipients x blasts.
 * They do not. A row is written only when a request *changes* somebody's
 * status, so a person who has left produces no more rows however many further
 * messages reach them: measured, one supporter reached by six blasts, each copy
 * carrying its own link and every link used once, wrote **one** row. The
 * quantity is acts of leaving, whose ceiling over a campaign's life is its
 * supporter list plus whatever an operator re-subscribes -- bounded by the
 * list, not by the sending. `tests/Campaign/UnsubscribeTest.php` pins it.
 *
 * **And that is why there is no retention window (D-49's second half).** A row
 * costs 75.0 bytes attributed and 66.8 unattributed, flat, so a campaign of a
 * quarter of a million supporters who all leave tops out near 18.8 MB, once,
 * rather than accumulating with every send -- against the 1.4 GB a year that
 * Phase 2 Step 6 refused to prune off `blast_recipients`. There is nothing to
 * bound, and D-49's first half already established there is nothing an erasure
 * must reach here. Pruning would also make the blast list lie in the one way
 * Step 4's four answers exist to prevent: measured by running it, removing the
 * rows for a blast whose copies all carried links leaves `attributable_count`
 * standing and takes `withdrawn_count` to zero, which that page renders as
 * "Nobody" -- of people who did leave. Nothing else records that a link was
 * used, so the page could only be made honest again by maintaining a retained
 * count on `blasts`: a migration and a writer on the sending path, to preserve
 * a number whose rows weigh 75 bytes. No window, and no fifth schedule
 * declaration to sit on the scheduler nothing runs (deferral 17).
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
