<?php

declare(strict_types=1);

namespace App\Blasts;

use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Keeps a worker from handing copies to the mailer faster than the provider
 * accepts them.
 *
 * **A pace, not a retry, because a refused copy is final.** Past its maximum
 * sending rate SES *"drops the message and doesn't attempt to redeliver it"*,
 * and the SDK's own three attempts all land inside the second SES is still
 * counting. SendBlast then records the refusal on the copy's row, and a claimed
 * row with a reason is final by the claim's design: making it retryable would
 * reopen the at-most-once argument the claim settles. Measured at Phase 7
 * Step 4 (measurement 1): an unpaced blast of six copies under a simulated
 * limit of one per second sent one, recorded five as failed, and still read
 * Sent. So the refusal has to be kept from happening, and nothing but a pace
 * does that.
 *
 * **One pace per worker process, shared by every blast it sends, which is why
 * this is a singleton.** The rate is the provider account's, not a blast's or a
 * campaign's. A worker runs one campaign's blast and then another's in the same
 * process, and a pace that lived in each job would let the second blast's first
 * copy follow the first blast's last one by milliseconds. The worker clears
 * only scoped instances between jobs, so this one lasts as long as the process,
 * and a deploy's restart begins it again with no handover to wait on.
 *
 * **The interval runs from one handover to the next.** The time a send takes
 * counts toward the interval rather than being added to it, so a send slower
 * than the interval costs no pause at all.
 *
 * **What it does not pace, named.** The rate is the account's, so an invitation
 * or a password reset sent from a request during a blast shares it, and nothing
 * here sees those. Nor does a second worker process share this one's clock. A
 * limiter shared across processes is not built until something needs one.
 */
#[Singleton]
final class SendPace
{
    /**
     * Microseconds between handovers, or null when sending is unpaced.
     */
    private readonly ?float $interval;

    private ?CarbonInterface $lastHandoverAt = null;

    /**
     * Read the rate once, when the first send in this process asks for it.
     *
     * **Unset or empty means unpaced**, which is development and the suite. A
     * positive number is messages per second, fractions included, because a
     * provider's quota need not be whole: `0.5` is one copy every two seconds.
     * **Anything else is refused rather than read as unpaced**: zero, a negative
     * number and a non-number each say somebody meant to set a pace and did not
     * manage to, and reading them as unpaced would bring back the silent loss
     * this class exists to prevent. A refusal is raised while a job resolves
     * this class, which is before its blast is marked sending and before anybody
     * is claimed, so it fails that blast visibly and costs no copy.
     */
    public function __construct(#[Config('mail.send_rate')] mixed $rate)
    {
        if ($rate === null || $rate === '') {
            $this->interval = null;

            return;
        }

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('MAIL_SEND_RATE must be a positive number of messages per second, or empty to send unpaced.');
        }

        $this->interval = 1_000_000 / (float) $rate;
    }

    /**
     * Wait until the next copy may be handed over, then count it as handed over.
     *
     * Called only for a copy that is about to go to the mailer, never for one
     * whose claim found it already taken.
     */
    public function awaitTurn(): void
    {
        if ($this->interval === null) {
            return;
        }

        if ($this->lastHandoverAt !== null) {
            $remaining = $this->interval - $this->lastHandoverAt->diffInMicroseconds(now());

            if ($remaining > 0) {
                Sleep::for((int) round($remaining))->microseconds();
            }
        }

        $this->lastHandoverAt = now();
    }
}
