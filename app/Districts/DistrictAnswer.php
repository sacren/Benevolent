<?php

declare(strict_types=1);

namespace App\Districts;

/**
 * The five things the product can say about a supporter's district.
 *
 * **Four of them are "we cannot name one", and they are kept apart because they
 * ask four different things of an operator (D-42).** A split ZIP is nobody's
 * mistake and needs a street address the product deliberately does not hold; a
 * ZIP with no district data is a real ZIP the Census gives no area; a value that
 * is not a ZIP is data somebody can correct; and no ZIP at all is a gap
 * somebody can fill. Folded into one "unknown", a page teaches an operator to
 * ignore the lot -- including the one supporter in several whose record they
 * could fix in a minute.
 *
 * Backed by the strings the supporter list receives, through
 * DistrictClaim::jsonSerialize(), and mirrored by `DistrictAnswer` in
 * resources/js/types/districts.ts.
 */
enum DistrictAnswer: string
{
    /**
     * The ZIP's whole area lies in one district, so that district is claimed.
     */
    case Placed = 'placed';

    /**
     * The ZIP's area crosses a district boundary, so no district is claimed
     * (D-32): the supporter may be in any of the districts it touches.
     */
    case Split = 'split';

    /**
     * A well-formed ZIP the Census relation has no area for -- PO-box-only,
     * single-recipient and military ZIPs are the usual reasons.
     */
    case Unmapped = 'unmapped';

    /**
     * What is stored is not a five-digit ZIP or a ZIP+4.
     */
    case Malformed = 'malformed';

    /**
     * No ZIP is stored at all.
     */
    case Missing = 'missing';
}
