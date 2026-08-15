<?php

declare(strict_types=1);

namespace App\Segments;

use App\Districts\DistrictNarrowing;
use App\Districts\ZctaDistricts;
use App\Models\Segment;
use App\Models\Supporter;
use App\Supporters\PostcodeNarrowing;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrowing a query over supporters to whoever a segment names.
 *
 * **The one place a segment's rule is turned into a query for the supporter
 * list and its export.** A segment now holds one of two rules (D-37): ZIP code
 * prefixes, answered by App\Supporters\PostcodeNarrowing, or a congressional
 * district, answered by App\Districts\DistrictNarrowing. Both surfaces must
 * answer the same question about the same segment -- the export exists to hand
 * back exactly what the page showed -- so they ask here rather than each
 * choosing between the two, which would be two copies of the choice free to
 * drift.
 *
 * **Every way of not being able to answer narrows to nobody.** A district
 * segment naming a seat the relation does not have -- written by hand, or left
 * behind when a later release shipped a relation that dropped it -- reaches
 * nobody, never the whole list, for the reason PostcodeNarrowing gives for its
 * own empty rule: over-inclusion is the direction that cannot be taken back.
 * A segment with neither rule cannot be stored (`segments_narrow_one_way_only`)
 * and is answered the same way.
 *
 * **App\Blasts\BlastAudience comes here for a draft, and deliberately not for
 * a committed blast (D-38).** A draft points at its segment, so the audience a
 * compose page shows is this rule, whichever kind the segment is -- which is
 * what makes a blast's count and the supporter list's rows the same answer to
 * the same question. A committed blast replays what it froze instead, and never
 * consults a segment at all, because a segment stays editable after the
 * campaign has given up the right to change the message.
 */
final class SegmentNarrowing
{
    /**
     * Narrow a supporter query to whoever the segment names.
     *
     * The relation is taken from the caller when it has already read one --
     * the supporter list reads it for its District column anyway -- and read
     * here only for a district segment when it has not, because reading it
     * costs about 14 ms and 11 MB that a prefix segment has no use for.
     *
     * @param  Builder<Supporter>  $query
     * @return Builder<Supporter>
     */
    public static function apply(Builder $query, Segment $segment, ?ZctaDistricts $relation = null): Builder
    {
        if ($segment->postcode_prefixes !== null) {
            return PostcodeNarrowing::apply($query, $segment->postcode_prefixes);
        }

        if ($segment->district === null) {
            return $query->whereRaw('false');
        }

        $relation ??= ZctaDistricts::shipped();
        $seat = $segment->seat($relation);

        if ($seat === null) {
            return $query->whereRaw('false');
        }

        return DistrictNarrowing::apply($query, $seat, $relation);
    }
}
