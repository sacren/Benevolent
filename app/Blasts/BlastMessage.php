<?php

declare(strict_types=1);

namespace App\Blasts;

use App\Models\Blast;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One campaign's message, as it arrives in a supporter's inbox.
 *
 * **Filed with its module rather than in an app/Mail/ directory, and no new
 * base folder was needed.** D-6 asked the same question of this application's
 * first queued job and answered it by measurement: nothing discovers a job by
 * path, so its location is filing rather than wiring, and a module's code goes
 * under the module's name. A Mailable is discovered by nothing either -- it is
 * a class somebody constructs -- so the same reasoning applies unchanged, and
 * `app/Mail/` would be a kind-named directory in an application that has chosen
 * concern-named ones everywhere else.
 *
 * **The envelope, which is D-15.** The *from address* is the platform's, and
 * stays that way: sending as `hello@harbor-cleanup.test` needs SPF and DKIM
 * alignment, a verified domain and a bounce path, which is a credential
 * question rather than a configuration one and drags per-campaign secret
 * storage in with it (deferral 18, unfired). The *from name* is the campaign's
 * already, applied by CampaignMailFromTenancyBootstrapper, so nothing here sets
 * it -- and nothing here should, because a from-name set at this call site
 * would be a second copy of that rule, free to drift from the first.
 *
 * What this class adds is the **reply path**. A password reset is a message
 * nobody answers; an advocacy message is one where the answer is the point, and
 * a campaign that writes to ten thousand people from an address that discards
 * their replies has done something worse than not writing. `Reply-To` costs no
 * DNS record and no secret.
 *
 * **A campaign with no reply address sends without one rather than falling back
 * to the platform's**, which would route a supporter's answer to people who
 * cannot act on it and who never asked to receive it. Missing is the honest
 * state and the surface that offers to send says so.
 *
 * **The letterhead, which is deferral 21, and it does not fire.** The deferral
 * fires only if the body is rendered by the framework's own notification
 * templates, which print `config('app.name')` as the mail header, the HTML title
 * and the footer copyright -- so a campaign's advocacy message would arrive
 * headed by the platform. This is its own Mailable with its own template, so
 * the letterhead is the campaign's by construction and `app.name` is never
 * touched: the browser title on every campaign page and the name in the split
 * auth layout read that same key, and steering it would move all three.
 * Deferral 21 stays deferred as the branding question it actually is -- colour,
 * logo, per-product variation -- with its trigger unchanged.
 *
 * **Plain text, deliberately.** The body is a plain textarea with no template
 * language behind it (D-20 is Step 6's and expects to answer "no"), so text is
 * what the operator wrote and what they can predict the look of. It also has no
 * second rendering to disagree with the first.
 */
final class BlastMessage extends Mailable
{
    /**
     * @param  Blast  $blast  the message as the campaign wrote it
     * @param  string  $campaignName  the campaign's own name, for the sign-off
     * @param  string|null  $replyAddress  where an answer should go, if the campaign said
     * @param  string  $unsubscribeUrl  this one recipient's way off the list
     *
     * Named `replyAddress` rather than the obvious `replyTo`, which is not
     * available: Mailable already declares a public `$replyTo` array, and a
     * promoted readonly property of that name is a fatal
     * "cannot redeclare non-readonly property ... as readonly" at class load.
     *
     * **`$unsubscribeUrl` is a string rather than the Supporter it belongs to,
     * and that is a boundary rather than a convenience.** Handing this class
     * the supporter would be the shorter code and would make the message able
     * to greet somebody by name -- which is D-20, Step 6's, and expected to
     * resolve as no. Passing the finished link means the refusal is structural:
     * there is no name in this object to print, so personalization cannot
     * arrive here by accident and later be discovered as a feature nobody
     * decided on.
     *
     * **This is nonetheless the first time two recipients get different bytes,
     * and that is worth naming rather than letting it be noticed later.** Until
     * now the same message went to everybody. A per-supporter link makes that
     * false. It is *addressing* -- which envelope this copy belongs to -- and
     * not personalization, and the distinction is exactly the one above: the
     * body still says nothing about who is reading it.
     */
    public function __construct(
        private readonly Blast $blast,
        private readonly string $campaignName,
        private readonly ?string $replyAddress,
        private readonly string $unsubscribeUrl,
    ) {}

    /**
     * The message's envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->blast->subject,

            // Named with the campaign so a supporter's mail client shows who
            // they would be answering, rather than a bare address. An empty
            // array is "no reply path" and is what a campaign that has not set
            // an address gets -- never the platform's own address, which would
            // send a supporter's answer somewhere nobody reads.
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
            text: 'mail.blast',
            with: [
                'body' => $this->blast->body,
                'campaignName' => $this->campaignName,
                'replyTo' => $this->replyAddress,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
