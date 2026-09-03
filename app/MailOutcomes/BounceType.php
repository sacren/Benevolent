<?php

declare(strict_types=1);

namespace App\MailOutcomes;

/**
 * What kind of bounce the provider reported for one copy (D-87).
 *
 * SES's own three values, kept verbatim so a stored row reads the same as the
 * notice that wrote it. The subtype is not kept: nothing reads it, and D-87
 * recorded the type alone.
 */
enum BounceType: string
{
    /**
     * The address does not accept mail and will not. The only bounce that
     * says something about the address rather than about this one message,
     * and so the only one a later bounce of another kind cannot replace.
     */
    case Permanent = 'Permanent';

    /**
     * This message did not arrive this time: a full mailbox, an auto-reply, a
     * content block. Both observed (B8) said nothing about the address.
     */
    case Transient = 'Transient';

    /**
     * The provider could not tell which. Ranked with Transient: it says
     * nothing about the address either.
     */
    case Undetermined = 'Undetermined';
}
