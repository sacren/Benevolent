<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supporter;
use App\Models\Unsubscribe;
use App\Supporters\SubscriptionStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How somebody stops a campaign writing to them.
 *
 * **The first surface this product serves to a person with no account, no
 * session and no operator behind them**, and the only page-rendering campaign
 * route outside the `auth` group. Every other one sits behind `auth` and
 * `verified`; the single prior exception is `.well-known/passkey-endpoints`,
 * which returns JSON and renders nothing.
 *
 * **Authority is the token, and its scope is the campaign's own database.**
 * There is no policy here and there is nothing for one to answer: a policy
 * decides what a signed-in operator may do, and this visitor is not one. What
 * takes its place is the lookup itself. The campaign is resolved from the
 * request's Host header before this controller runs, so `Supporter::query()`
 * reads that campaign's database and no other -- and a token minted in one
 * campaign, presented on another campaign's host, matches no row for the same
 * reason a supporter does not. **That is DEC-1's isolation doing the work,
 * which is why D-16(a) chose a stored token over a signed URL**: a signature is
 * taken over the platform-wide APP_KEY and separates campaigns only by the
 * hostname inside the signed string.
 *
 * **An unknown token is a 404, and that is the honest answer in every case that
 * produces one** -- a stranger guessing, a link belonging to another campaign,
 * or a supporter who has since been erased. After an erasure there is no row,
 * so there is nobody to unsubscribe and nothing to say about who used to be
 * there. A malformed token never reaches a query at all: the column is `uuid`,
 * PostgreSQL raises SQLSTATE 22P02 rather than matching nothing, and the
 * route's `whereUuid` constraint answers 404 from the router first.
 *
 * **Nothing here is recorded in the audit trail, and that is D-4 and D-7
 * unchanged rather than a new decision.** The trail's subject is changes to who
 * may act in this campaign and with what authority; its three cases are all
 * roster changes. A supporter is not an operator and unsubscribing is not an
 * exercise of campaign authority, so this is further outside the trail's scope
 * than a send was -- and D-17 already answered that one no. **What is recorded
 * is the withdrawal itself, in `unsubscribes` (D-47)**: a module table of
 * events that a person, not an operator, caused, which is exactly why it is
 * not the trail.
 */
class UnsubscribeController extends Controller
{
    /**
     * Show somebody what this link would do, without doing it.
     *
     * **A GET renders and a POST acts (D-16(b)), and the reason is a
     * measurement of how mail is actually handled rather than a preference for
     * REST.** Mail providers' link scanners, security appliances and client
     * prefetchers issue GET requests against every URL in a message, without a
     * human ever clicking -- so a `GET` that mutates would unsubscribe people
     * from mail they wanted, silently, on delivery. That is the exact failure
     * this step exists to prevent, arriving from the opposite direction.
     *
     * **One-click is not sacrificed to say that, which is worth stating because
     * the two look opposed.** The mail-client one-click standard is itself a
     * POST (RFC 8058's `List-Unsubscribe-Post`), for precisely this reason. So
     * the human path here and the machine path agree on the verb; the machine
     * path additionally needs a CSRF-exempt endpoint and a real provider to
     * verify against, and is recorded as a deferral rather than guessed at.
     *
     * The page's content is a function of the supporter's current status rather
     * than of which request produced it, so this same action answers before and
     * after. Somebody who follows the link a second time months later is told
     * they are already unsubscribed instead of being shown a form that would
     * appear to do nothing.
     */
    public function show(string $token): Response
    {
        return Inertia::render('Unsubscribe', $this->pageFor(
            $this->supporterFor($token),
            $token,
        ));
    }

    /**
     * Stop the campaign writing to them.
     *
     * **Idempotent, and it redirects back to the page that offered it.** A
     * second POST is not an error and does not need to be one -- the state it
     * asks for is the state already held. Redirecting rather than rendering
     * means a refresh re-issues the GET instead of re-submitting, so nobody
     * lands on a browser warning about resending a form for an act they were
     * told could not be undone.
     *
     * **The write is the status, and a record that it changed.** The supporter
     * is kept rather than deleted, because the record of the request is what
     * stops a later import putting them back -- the importer omits
     * `subscription_status` from its update list precisely so this holds, and a
     * test pins it. `BlastAudience` enforces subscribed-only in the query, so a
     * blast composed before this and sent after correctly leaves them out.
     *
     * **The withdrawal is recorded only when the status changes (D-47), in the
     * same transaction as the status.** A second POST asks for the state
     * already held, so it is not a second withdrawal and writes nothing; a POST
     * after an operator has put somebody back on the list is one, and is
     * recorded again. Recorded as **unattributed**: the token here is the
     * supporter's, which names a person and no message, so the row says so
     * rather than crediting whichever blast was sent last (D-46). One
     * transaction, so a withdrawal can never be recorded without the change it
     * records, nor the change made without its record.
     *
     * **This link unsubscribes and can never re-subscribe (D-21).** The
     * symmetric page looks kinder and is not: a link that could opt somebody
     * *in* turns a leaked or forwarded message into a way to put an address
     * back on a list it asked to leave, which is the harm in the direction that
     * cannot be taken back. Somebody who clicks by mistake asks the campaign,
     * which can set the status on the edit form. **Trigger to revisit:** the
     * first campaign that needs a preference centre.
     */
    public function store(string $token): RedirectResponse
    {
        $supporter = $this->supporterFor($token);

        // Written unconditionally, and the repeat is still free -- **which is
        // Eloquent's doing rather than this method's, and that is worth saying
        // because the obvious defensive `if` here was measured to do nothing.**
        // An earlier draft guarded this with a status comparison; removing it
        // reddened none of the fourteen tests, because `save()` on a model
        // whose attributes have not changed issues no statement at all, so
        // `updated_at` does not move either way. The guard read as the
        // mechanism and was a comment with syntax, so it is gone rather than
        // kept as decoration.
        //
        // The *test* stays, and it is a guard over framework behaviour rather
        // than over ours -- which is exactly the kind this project keeps,
        // because nothing else in the suite would notice it regressing. What
        // reddens it is a query-builder `update()`, which writes
        // unconditionally because there is no model to be clean, and which
        // would make the record say a supporter asked twice on two different
        // days. Note that a `touch()` does *not* redden it and is not a
        // counter-example: within the same second it sets `updated_at` to the
        // value already held, so Eloquent finds the model clean there too.
        //
        // **The same behaviour now decides whether a withdrawal happened.**
        // `wasChanged()` reads what that save actually wrote, so "the status
        // changed" is answered by the write itself rather than by a comparison
        // made beforehand that could disagree with it.
        DB::transaction(function () use ($supporter): void {
            $supporter->subscription_status = SubscriptionStatus::Unsubscribed;
            $supporter->save();

            if ($supporter->wasChanged('subscription_status')) {
                Unsubscribe::create(['blast_recipient_id' => null]);
            }
        });

        return to_route('unsubscribe.show', ['token' => $token]);
    }

    /**
     * The supporter this link belongs to, in this campaign, or nothing.
     *
     * A plain equality against the token column. There is no `whereEmailMatches`
     * folding to do here and there must not be: the address is data a person
     * typed and is matched case-insensitively by D-8, while this is a generated
     * credential compared exactly.
     */
    private function supporterFor(string $token): Supporter
    {
        return Supporter::query()
            ->where('unsubscribe_token', $token)
            ->firstOrFail();
    }

    /**
     * What the page is told.
     *
     * **The address is shown so a person knows which one they are removing**,
     * which matters for anybody whose household or work address is also on the
     * list. It discloses nothing to the holder of the link that the link did
     * not already imply, since it was mailed to that address.
     *
     * The campaign's name comes from the registry row tenancy is already
     * holding, so it costs no query -- and it is what tells a stranger whose
     * list this is, on a page that carries none of the platform's own chrome.
     *
     * @return array<string, mixed>
     */
    private function pageFor(Supporter $supporter, string $token): array
    {
        return [
            'token' => $token,
            'email' => $supporter->email,
            'campaignName' => trim((string) tenant('name')),
            'unsubscribed' => $supporter->subscription_status === SubscriptionStatus::Unsubscribed,
        ];
    }
}
