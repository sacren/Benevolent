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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * frozen at composing time could not do. The subscribed-only condition is not
 * here and never will be, because a column that could record "send to
 * unsubscribed people too" is a column that makes that sendable.
 *
 * **Where that rule is kept is two columns rather than one (D-26).** A blast
 * either points at a segment the campaign has named, or carries its own
 * `postcode_prefixes`, or does neither — and doing neither is the aim at every
 * supporter the campaign may contact. Shape (b): the pointer was added without
 * taking the column away, so every blast written before it kept saying exactly
 * what it was aimed at. The two are mutually exclusive as a fact about the row
 * rather than as a convention this class remembers; `blasts_aimed_one_way_only`
 * is the check constraint that holds it, and the migration says why an aim that
 * could be read two ways is a send hazard rather than an untidiness.
 *
 * **A committed blast's aim stops moving, and that is a third column rather
 * than a third way of aiming (D-27).** A segment is shared and mutable, so a
 * blast pointing at one has an aim somebody else can change -- correctly while
 * it is a draft, which is the whole value of pointing, and not at all once the
 * campaign has committed it. `committed_prefixes` is what the pointer said at
 * the moment of committing, written by the same statement that commits the
 * blast. It is a record and never an aim: `blasts_committed_aim_is_frozen` ties
 * a frozen rule to exactly the rows that have one, and
 * `blasts_aimed_one_way_only` is untouched because this column is not one of
 * the two it governs.
 *
 * **A segment that narrows by district freezes into a column of its own, and
 * which one a blast used is which reader replays it (D-38).** A district's rule
 * is not a literal the campaign typed: `MA-07` names whatever relation ships
 * with the release in force when the send finally runs, so what is frozen is
 * the ZIP codes themselves, in `committed_zip_codes`. The two frozen columns
 * are read by different rules -- `committed_prefixes` by
 * App\Supporters\PostcodeNarrowing, which reaches a stored `02141abc` through
 * `02141`, and `committed_zip_codes` by
 * App\Districts\DistrictNarrowing::toZipCodes(), which does not -- so the
 * column a value sits in is what says how to replay it, rather than the kind of
 * a segment a committed blast is not allowed to read. The constraint counts:
 * exactly one of the two on a committed segment-aimed blast, neither on
 * anything else.
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
 * @property int|null $segment_id
 * @property list<string>|null $postcode_prefixes
 * @property list<string>|null $committed_prefixes
 * @property list<string>|null $committed_zip_codes
 * @property-read Segment|null $segment
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
//
// **`segment_id` is here rather than stamped, unlike the two operator columns,
// because it is something the operator chooses and not something the server
// knows.** It is safe to accept for the reason the id is safe to accept at all:
// the form's `exists` rule runs on the campaign's own connection, so an id
// naming another campaign's segment does not resolve, and the check constraint
// refuses a row that names it alongside a rule of its own.
//
// **And it is load-bearing in the positive direction, which is the opposite of
// how this list has failed before.** Twice in this project a request object's
// allowlist has made a model's fillable list decorative, so that widening the
// list reddened nothing. Here the list is what *permits* the write: mass
// assignment drops a guarded attribute silently, so removing this name would
// leave every blast aimed at nobody in particular with no error anywhere. A
// test pins the list beside the behaviour for that reason.
#[Fillable(['subject', 'body', 'segment_id', 'postcode_prefixes'])]
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
            'committed_prefixes' => 'array',
            'committed_zip_codes' => 'array',
            'status' => BlastStatus::class,
            'queued_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The narrowing this blast is aimed at, if it points at one rather than
     * carrying its own.
     *
     * **Null here does not mean "everybody", and that is the trap this relation
     * carries.** It means "this blast's aim is not a segment" — which is either
     * its own `postcode_prefixes` or, when that is null too, the whole
     * contactable list. Putting the two together is one reader's job and no
     * other's: a reader that took a null segment for a null aim would widen a
     * narrowed blast, which is the one direction this module cannot recover
     * from.
     *
     * Added here with the column rather than with its first reader, unlike
     * `recipients()` below, because it is what makes the column legible: a bare
     * `segment_id` on a table whose other two foreign keys point at operators
     * reads as a fourth authorship column until something says otherwise.
     *
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
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
