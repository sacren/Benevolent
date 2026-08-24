<?php

declare(strict_types=1);

namespace App\Operators;

use App\Authorization\OperatorRole;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invitation to help run a campaign, as it arrives in somebody's inbox.
 *
 * **Not queued, and that is D-56 decided by measurement rather than by
 * preference.** Phase 6 Step 1 measured that Fortify's own account mail is sent
 * in the request (central `jobs` 0 → 0; neither `VerifyEmail` nor
 * `ResetPassword` implements `ShouldQueue`), and no environment this product
 * has ever run in has a worker (deferral 25). A queued invitation would sit in
 * `jobs` forever while the campaign's screen said it had been sent -- "an
 * invitation the campaign believes was sent and which never left", which is
 * the one thing D-56 says must not happen. So this class implements no
 * `ShouldQueue`, InviteOperator sends it with `send()`, and a test asserts both.
 *
 * **What that lets the product claim, and no more.** Sent in the request means
 * the transport accepted it before the Owner was told anything, and a
 * transport that refuses it undoes the invitation (InviteOperator). It does not
 * mean it arrived: a bounce happens later and elsewhere, and this platform has
 * no bounce path (deferral 30). The page that sends it says exactly that.
 *
 * **The envelope is D-15's, unchanged.** The from name is the campaign's,
 * applied by CampaignMailFromTenancyBootstrapper; the from address is the
 * platform's, because sending as the campaign's own domain needs a verified
 * domain and a secret no campaign has (deferral 18, unfired -- an invitation
 * to join a named campaign arriving from the platform's address is that
 * deferral's recorded shape, not a new defect). Replies go to the campaign's
 * contact address when it has one, and nowhere otherwise, for BlastMessage's
 * reason: an answer routed to the platform reaches people who cannot act on it.
 *
 * **Its own plain-text template, so the letterhead is the campaign's** and
 * deferral 21 does not fire, for the reason it does not fire for a blast.
 */
final class OperatorInvitationMessage extends Mailable
{
    /**
     * @param  string  $campaignName  the campaign's own name
     * @param  OperatorRole  $role  the authority the link will grant
     * @param  string  $acceptUrl  the link, carrying the credential
     * @param  string|null  $replyAddress  where an answer should go, if the campaign said
     */
    public function __construct(
        private readonly string $campaignName,
        private readonly OperatorRole $role,
        private readonly string $acceptUrl,
        private readonly ?string $replyAddress,
    ) {}

    /**
     * The message's envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to help run :campaign', ['campaign' => $this->campaignName]),
            replyTo: $this->replyAddress === null
                ? []
                : [new Address($this->replyAddress, $this->campaignName)],
        );
    }

    /**
     * The message's content.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.operator-invitation',
            with: [
                'campaignName' => $this->campaignName,
                'asOwner' => $this->role === OperatorRole::Owner,
                'acceptUrl' => $this->acceptUrl,
                'replyTo' => $this->replyAddress,
            ],
        );
    }
}
