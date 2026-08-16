<?php

declare(strict_types=1);

namespace App\Blasts;

use App\Models\Blast;
use App\Models\Supporter;
use App\Tenancy\CampaignContact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends one blast to the people its rule selects.
 *
 * **One job per blast, not one per recipient (D-18).** A job per recipient
 * would put one row into the *central* `jobs` table for every person a campaign
 * writes to -- 225,000 payloads for one send of one campaign, in the one table
 * DEC-1 says must not accumulate a campaign's work -- and it buys nothing that
 * is not already had. Resumption is carried by `blast_recipients` (D-14), which
 * exists whichever shape this takes; and per-message failure isolation is had
 * inside a blast-level job by recording the failure on the recipient's own row
 * and carrying on, which is what happens below. A third shape was considered --
 * one job per chunk, re-dispatching itself -- and is not needed while the claim
 * below makes a duplicate delivery impossible rather than merely unlikely.
 * **Trigger to revisit:** the first send measured to exceed the queue's
 * visibility window in a way the claim does not already make safe.
 *
 * **A recipient is claimed before the message is handed over, never after.**
 * That is the whole of the at-most-once guarantee and it is the choice this
 * module cannot get wrong twice: a duplicate is in somebody's inbox and no
 * later commit recalls it, while an unsent recipient is a row that says so. The
 * cost is paid in the open -- a process killed between the claim and the send
 * leaves a row carrying neither a `sent_at` nor a reason, which is the true
 * statement that nobody knows whether it went.
 *
 * **The claim has to be the database's answer, because `retry_after` is 90
 * seconds.** Any real send runs longer, so the database queue releases the job
 * and a second worker takes it *while the first is still running* -- by
 * default, with nothing misconfigured. That is one campaign, one blast, one
 * dispatch and two senders, which no dispatch-time lock addresses at all. The
 * unique index on (blast_id, supporter_id) is what holds; `insertOrIgnore`
 * turns "has this person already had it?" into the write itself rather than a
 * read another worker can race.
 *
 * **The campaign goes in the lock key, never in the container (deferral 23,
 * L-27).** WithoutOverlapping resolves the container's cache repository, which
 * escapes the tenancy package's per-campaign tagging entirely -- measured three
 * separate times in this project, and tags never reach a lock name in any case.
 * A key built from a blast id alone would be one lock shared by every campaign,
 * because every campaign's ids restart at 1, and one campaign's send would be
 * silently turned away by another campaign's. The campaign id is carried as a
 * property rather than read from `tenant()` at middleware time, so the key does
 * not depend on which listener has run yet.
 *
 * **This job carries identifiers, never rows.** A model property serializes as
 * a ModelIdentifier, so no supporter reaches the central payload; the campaign
 * id is a plain string and is written verbatim, which is exactly why it is a
 * campaign id and not anything about a person.
 *
 * **And every statement here binds keys and timestamps, never people** -- which
 * is a different answer from the importer's, and a better one. Phase 1 had to
 * guard two statements whose bindings were addresses, because a QueryException
 * inlines every binding into the SQL it prints. Nothing here does: the audience
 * query binds a status and either folded postcode prefixes or a district's ZIP
 * codes with the pattern that checks them -- a rule an operator wrote or ZIP
 * codes read from the shipped relation, never a value from a supporter's row --
 * and the claim and its resolution bind two integers and a timestamp. The one
 * place an address exists in this file is the mail envelope, which is not a
 * database binding -- and the transport's *complaint* about it is scrubbed
 * below before it is stored.
 */
final class SendBlast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many supporters are read at a time.
     *
     * Bounds the memory a list of any size costs. Measured at Step 3: chunking
     * 225,000 recipients 500 at a time costs 986 ms at flat memory, and the
     * per-recipient write rather than the audience query is what dominates a
     * real send.
     */
    private const int CHUNK = 500;

    /**
     * How much of a transport's complaint is kept.
     *
     * Long enough for the useful half -- an SMTP response code and its reason --
     * and bounded because the column is text and there is one row per recipient.
     */
    private const int REASON_LIMIT = 500;

    /**
     * Retried, and safe to retry only because of the claim.
     *
     * A transient fault part-way through a send should resume rather than
     * abandon a half-written blast, and resuming is exactly what re-entering
     * this job does: every recipient already claimed is skipped by the unique
     * index. Without that index this number would have to be 1, and a blast
     * that failed at 3,000 of 12,000 could only be given up on.
     */
    public int $tries = 3;

    public function __construct(
        private readonly Blast $blast,
        private readonly string $campaignId,
    ) {}

    /**
     * Keep two workers from running one campaign's blast at the same time.
     *
     * The unique index is what makes a duplicate delivery impossible; this is
     * what stops the second worker doing the work at all, rather than doing it
     * and being refused a row at a time.
     *
     * `expireAfter` is not optional. Without it the lock is held until the job
     * releases it, so a worker killed mid-send would leave a blast that no
     * later attempt could ever pick up -- a send stuck forever, by a mechanism
     * added to make sending safer.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->campaignId.':blast:'.$this->blast->getKey()))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
    }

    public function handle(): void
    {
        $blast = $this->blast;

        if (! $blast->status->isCommitted()) {
            // Only reachable if something dispatched this around the controller,
            // which is the one place that moves a blast out of draft. Said in a
            // sentence rather than left to send a message the campaign never
            // committed -- and never by committing it here, because that would
            // put the irreversible decision in the thing that carries it out.
            throw new RuntimeException('This blast has not been committed to sending.');
        }

        $blast->forceFill([
            'status' => BlastStatus::Sending,
            'failure_reason' => null,
        ])->save();

        // Read once, outside the loop. Both are the campaign's own and neither
        // changes during a send; reading them per message would be a query per
        // recipient for a value that cannot move.
        $campaignName = trim((string) tenant('name'));
        $replyTo = CampaignContact::address();

        // **The audience is consumed here, never re-derived.** BlastAudience
        // returns a query precisely so that the count an operator was shown and
        // the set this walks come from the same lines of code. A second query
        // written here would be the defect that class exists to prevent.
        BlastAudience::for($blast)->chunkById(
            self::CHUNK,
            function ($supporters) use ($blast, $campaignName, $replyTo): void {
                foreach ($supporters as $supporter) {
                    $this->deliver($blast, $supporter, $campaignName, $replyTo);
                }
            }
        );

        $blast->forceFill([
            'status' => BlastStatus::Sent,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Record why the send stopped, where the operator who started it can read it.
     *
     * Written into the campaign's own row rather than left to central
     * `failed_jobs`, which carries no campaign column and which no campaign
     * surface reads. This hook still runs in campaign context -- measured for
     * the importer at Phase 1 Step 4; it is the JobFailed listener writing the
     * central row that runs after tenancy reverts, not this.
     *
     * Whatever went out stays gone. There is no unwinding a blast, which is why
     * Failed is a state of its own rather than a way back to draft, and why the
     * recipient rows are the answer to "who had already had it".
     */
    public function failed(Throwable $exception): void
    {
        $this->blast->forceFill([
            'status' => BlastStatus::Failed,
            'failure_reason' => Str::limit($exception->getMessage(), self::REASON_LIMIT),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Give one supporter their copy, unless they have already had it.
     */
    private function deliver(Blast $blast, Supporter $supporter, string $campaignName, ?string $replyTo): void
    {
        if (! $this->claim($blast, $supporter)) {
            // Already reached, by an earlier attempt at this job or by another
            // worker running it right now. Skipped in silence: a duplicate claim
            // is the mechanism working rather than anything to report.
            return;
        }

        $failure = null;

        try {
            Mail::to($supporter->email)->send(new BlastMessage(
                $blast,
                $campaignName,
                $replyTo,
                $this->unsubscribeUrlFor($supporter),
            ));
        } catch (Throwable $exception) {
            $failure = $this->withoutNamingAnybody($exception, $supporter->email);
        }

        // Resolved outside the try, so that a fault in *recording* the send
        // cannot be mistaken for a fault in the send. Writing the failure from
        // inside the catch would also let a message that went out be marked as
        // one that did not -- and the table's check constraint would refuse the
        // row rather than accept the contradiction, turning one bad record into
        // a failed send.
        $this->resolve($blast, $supporter, $failure);
    }

    /**
     * Where this one supporter goes to stop receiving mail.
     *
     * **The one value in a blast that differs per recipient, and the reason it
     * is built here rather than read from a column.** The token is the
     * supporter's; the URL around it is this campaign's hostname plus a route,
     * neither of which belongs in the database.
     *
     * **The absolute host is the load-bearing part, and it is the thing most
     * likely to be wrong in exactly this context.** A queued job has no request
     * to take a root URL from, so `route()` would fall back to APP_URL -- the
     * *central* host, where campaign routes are deliberately unreachable, and
     * where this link would therefore 404 for every supporter who clicked it.
     * `CampaignHostTenancyBootstrapper` is what makes it right: it forces the
     * root onto the campaign's own hostname when tenancy initializes, which is
     * precisely the case its docblock was written for. That is asserted with
     * two campaigns rather than assumed, because a URL generator that captured
     * one campaign's host and served it to the next would send one campaign's
     * supporters to another campaign's site -- the L-21 family, with somebody
     * else's unsubscribe page at the end of it.
     *
     * Unsigned, deliberately: the token *is* the credential (D-16(a)), and a
     * signature over the platform-wide APP_KEY would add a second one that
     * separates campaigns only by the hostname inside it.
     */
    private function unsubscribeUrlFor(Supporter $supporter): string
    {
        return route('unsubscribe.show', ['token' => $supporter->unsubscribe_token]);
    }

    /**
     * Take this supporter's copy of this blast, or report that somebody already has.
     *
     * `insertOrIgnore` rather than an insert in a try: PostgreSQL's
     * `on conflict do nothing` neither raises nor aborts the surrounding
     * transaction, where a refused insert would do both -- and every test in
     * the campaign suite runs inside one, so a claim written the other way
     * would take the whole test with it.
     */
    private function claim(Blast $blast, Supporter $supporter): bool
    {
        $now = now();

        return DB::table('blast_recipients')->insertOrIgnore([
            'blast_id' => $blast->getKey(),
            'supporter_id' => $supporter->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Say what became of one claimed message.
     */
    private function resolve(Blast $blast, Supporter $supporter, ?string $failure): void
    {
        DB::table('blast_recipients')
            ->where('blast_id', $blast->getKey())
            ->where('supporter_id', $supporter->getKey())
            ->update($failure === null
                ? ['sent_at' => now(), 'updated_at' => now()]
                : ['failure_reason' => $failure, 'updated_at' => now()]);
    }

    /**
     * A transport's complaint, with the address it is complaining about removed.
     *
     * **A mail failure names the person it failed to reach**, and it names them
     * in the words of whatever refused the message: an SMTP server answering
     * `550 5.1.1 <someone@example.test>: user unknown` puts an address straight
     * into the reason. Stored as-is that would make `blast_recipients` hold the
     * one thing its schema was designed not to -- a copy of a supporter's
     * address, in a row an erasure nulls the key of but cannot clean the text
     * of, which is exactly how D-10 stops being true.
     *
     * This is the same door Phase 1 found in a QueryException's inlined
     * bindings, arriving from the other side: there the database named the
     * person, here the mail transport does.
     *
     * The address is replaced rather than the message discarded, because the
     * half that matters is *why* -- the row already names who, by key. What
     * survives is the class of the failure and the server's own words about it.
     */
    private function withoutNamingAnybody(Throwable $exception, string $email): string
    {
        $reason = str_ireplace($email, '[the address]', $exception->getMessage());

        return Str::limit(sprintf('%s: %s', class_basename($exception), $reason), self::REASON_LIMIT);
    }
}
