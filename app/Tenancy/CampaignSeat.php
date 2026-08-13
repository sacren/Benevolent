<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Tenant as Campaign;

/**
 * The seat in the House a campaign is running for, if it has said.
 *
 * **Optional, and recording none forecloses nothing (D-40).** A candidate
 * committee runs for one seat; a party committee, a state-wide race or an issue
 * campaign lobbying several members runs for none, and for those the question
 * "is this supporter in my district" simply does not arise. So a campaign with
 * no seat is an ordinary campaign, not an incomplete one, and nothing requires
 * one -- which would also make it a per-campaign prerequisite for something,
 * the thing D-13 found this repository does not have.
 *
 * **Stored as the seat's name, `MA-07`, and not as boundaries of any
 * Congress's.** A seat keeps its name from one Congress to the next while its
 * ground moves (D-43), and the name is also what somebody reading the registry
 * row by hand can recognise. It is written only after Seat::parse() has found
 * it in the shipped relation, and read back through the same parse.
 *
 * **On the campaign's registry row, the way its contact address is** --
 * `tenants.data` through VirtualColumn, no migration, and no query to read
 * inside campaign context, because the campaign's own record is the object
 * tenancy is already holding. CampaignContact records why that is the safest
 * mechanism as well as the thinnest; everything it says applies here.
 */
final class CampaignSeat
{
    /**
     * The key this value is stored under on the campaign's registry row.
     *
     * Named once, for CampaignContact's reason: a mistyped key reads as null,
     * which is indistinguishable from a campaign that recorded no seat.
     */
    public const string KEY = 'seat';

    /**
     * The current campaign's seat as stored, or null if it has none -- and
     * null outside campaign context, where there is no campaign to ask.
     */
    public static function stored(): ?string
    {
        $stored = tenant(self::KEY);

        if (! is_string($stored)) {
            return null;
        }

        $seat = trim($stored);

        return $seat === '' ? null : $seat;
    }

    /**
     * The current campaign's seat, as the relation names it.
     *
     * Null for a campaign with no seat, and also for one whose stored seat the
     * relation does not name -- written by hand, or left behind by a later
     * release whose relation no longer has it. Neither can be compared with
     * anybody's district, so neither is treated as though it could.
     */
    public static function current(ZctaDistricts $relation): ?Seat
    {
        $stored = self::stored();

        return $stored === null ? null : Seat::parse($stored, $relation);
    }

    /**
     * Record the seat a campaign is running for.
     *
     * Takes the campaign rather than reading the active one, because the one
     * caller -- `campaign:seat` -- addresses a campaign by slug from central.
     */
    public static function store(Campaign $campaign, Seat $seat): void
    {
        $campaign->setAttribute(self::KEY, $seat->label());
        $campaign->save();
    }
}
