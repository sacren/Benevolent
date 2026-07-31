<?php

declare(strict_types=1);

namespace App\Models;

use App\Blasts\BlastPolicy;
use App\Blasts\BlastStatus;
use Database\Factories\BlastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A message a campaign has written to the people on its list.
 *
 * Sits in app/Models/ while the vocabulary it speaks lives in app/Blasts/, the
 * same split this application already makes three times over — OperatorRole and
 * Permission in app/Authorization/, AuditEvent in app/Audit/, SubscriptionStatus
 * and the import vocabulary in app/Supporters/. Eloquent models are where the
 * framework and every convention look for them; the domain's words follow the
 * module.
 *
 * **This model deliberately names no connection.** That is not an omission: it
 * means a blast follows the default connection, which tenancy has already
 * switched onto the campaign serving the request, so a campaign's messages land
 * in the campaign's own database. Naming a connection here — central, most
 * plausibly, since "a campaign's sent mail" sounds like platform data, and
 * doubly so because the `jobs` and `failed_jobs` tables beside it genuinely are
 * — would pool every campaign's blasts into one table and let a reader of one
 * campaign see what another said to its supporters. Supporter and AuditEntry
 * say the same thing from their own side, and the migration says it from the
 * schema's.
 *
 * **What a blast is addressed to is a rule, never a list (D-14).** The audience
 * is computed when sending starts, so a supporter who unsubscribes after the
 * message is written is correctly left out of it — which a recipient list
 * frozen at composing time could not do. `postcode_prefixes` is the whole of
 * what is stored, and null means every supporter the campaign may contact; the
 * subscribed-only condition is not here and never will be, because a column
 * that could record "send to unsubscribed people too" is a column that makes
 * that sendable.
 *
 * **A blast that has left Draft can never return to it.** `queued_at` is the
 * moment the campaign committed the message to sending, and it is set once. The
 * database enforces the pairing with `status` as a check constraint rather than
 * leaving it to whatever writes here, because the alternative to sending twice
 * is not a bug anybody gets to fix afterwards.
 *
 * @property int $id
 * @property int|null $operator_id
 * @property int|null $queued_by
 * @property string $subject
 * @property string $body
 * @property list<string>|null $postcode_prefixes
 * @property BlastStatus $status
 * @property Carbon|null $queued_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// Named here rather than discovered by convention, the same way SupporterPolicy
// is attached and the audit observer is attached to User. A policy filed at
// App\Policies\BlastPolicy would be found by path guessing whether or not this
// line existed, which would make it impossible to prove the wiring does
// anything; with the policy beside its module, deleting this line turns the
// allow tests red and leaves the deny tests green against a model governed by
// nothing.
#[UsePolicy(BlastPolicy::class)]
// What a compose form is allowed to set, listed by name the way Supporter's is.
//
// **The six columns left out are the point of the list.** `status`,
// `queued_at`, `finished_at` and `failure_reason` are where a blast has got to,
// written by the sending path and by nothing an operator submits -- a form that
// could set them could mark a message sent that never went, or return a
// committed blast to draft, which is the one thing this module's check
// constraint exists to make impossible. `operator_id` is authorship and
// `queued_by` is who committed the message to sending, both stamped by the
// server from the signed-in operator, because a form that could set either
// could put somebody else's name on a message that went out.
#[Fillable(['subject', 'body', 'postcode_prefixes'])]
class Blast extends Model
{
    /** @use HasFactory<BlastFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'postcode_prefixes' => 'array',
            'status' => BlastStatus::class,
            'queued_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The people this blast has been written to, one row each.
     *
     * Added here rather than at the step that created the table, because until
     * the list page counted them nothing in the application read it -- and a
     * relation with no reader is the shape this project keeps refusing. Its
     * reader is `withCount`, which is also why this is worth having at all: a
     * count per blast is one aggregate over an index, where a count of the
     * *audience* would be a fresh query per row.
     *
     * @return HasMany<BlastRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(BlastRecipient::class);
    }
}
