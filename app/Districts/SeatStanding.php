<?php

declare(strict_types=1);

namespace App\Districts;

/**
 * Where a supporter stands against the seat their campaign is running for
 * (D-40), as far as a ZIP code can say.
 *
 * **Knowing the seat does not shrink "we cannot say" for a campaign's own
 * constituents, and that was measured before this was built.** A supporter
 * whose ZIP code crosses the seat's boundary is exactly as uncertain as before
 * -- that is MaybeIn, not In -- and for the median district that is 30% of its
 * land. What a seat adds is NotIn: a supporter whose ZIP code touches no part of
 * the seat is definitely outside it, where before they were only "not named".
 *
 * **Every one of these is relative to the map the relation was drawn for
 * (D-43).** A seat keeps its name across Congresses and its ground does not, so
 * in a state that redrew after that Congress, In and NotIn describe the old
 * boundaries. The page names the Congress for exactly that reason.
 */
enum SeatStanding: string
{
    /**
     * The ZIP code lies wholly inside one district, and it is the seat.
     */
    case In = 'in';

    /**
     * The ZIP code crosses the seat's boundary, so the supporter may be on
     * either side of it -- the seat is one of the districts it touches.
     */
    case MaybeIn = 'maybe';

    /**
     * No part of the ZIP code is in the seat: it lies in another district, or
     * crosses several that do not include it.
     */
    case NotIn = 'not';
}
