<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Districts\Seat;
use App\Districts\ZctaDistricts;
use App\Models\Tenant;
use App\Tenancy\CampaignSeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * Shows or sets the seat in the House a campaign is running for.
 *
 * **A console command, and not an operator ability (D-36).** Which seat a
 * campaign is running for is a fact about the campaign that the platform
 * records when it sets the campaign up, the way `campaign:contact` records where
 * its mail comes back to -- not something one operator decides for the others.
 * So no `Permission` case governs it; the trigger to revisit that is the first
 * surface that lets an operator set or change it.
 *
 * Addressed by slug, for `campaign:contact`'s reason. The seat is checked
 * against the district data this release ships before anything is written, so
 * a typo -- or a seat that does not exist, like `CA-53` -- costs a message
 * rather than a campaign compared against nothing.
 *
 * **No way to clear one, deliberately**, and for the contact address's reason:
 * nothing has asked for it. Trigger to revisit: the first campaign that stops
 * running for the seat it recorded.
 */
#[Signature('campaign:seat {slug : The campaign slug} {seat? : The seat, written like CA-37 or AK-AL; omit to show the current one}')]
#[Description('Show or set the seat in the House a campaign is running for.')]
class SetCampaignSeat extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        $campaign = Tenant::query()->where('slug', $slug)->first();

        if ($campaign === null) {
            $this->components->error("No campaign with the slug \"{$slug}\".");

            return self::FAILURE;
        }

        $typed = $this->argument('seat');

        if (! is_string($typed)) {
            // Read from the registry row rather than through
            // CampaignSeat::stored(), which answers for whichever campaign is
            // active -- and none is, since this command runs centrally.
            $stored = $campaign->getAttribute(CampaignSeat::KEY);

            $this->components->info(is_string($stored) && trim($stored) !== ''
                ? "\"{$campaign->name}\" is running for {$stored}."
                : "\"{$campaign->name}\" has recorded no seat.");

            return self::SUCCESS;
        }

        $relation = ZctaDistricts::shipped();
        $seat = Seat::parse($typed, $relation);

        if (! $seat instanceof Seat) {
            $congress = Number::ordinal($relation->congress());

            $this->components->error("\"{$typed}\" is not a seat in the {$congress} Congress's districts. Write it as a state and a district, like CA-37, or AK-AL for a state's only seat.");

            return self::FAILURE;
        }

        CampaignSeat::store($campaign, $seat);

        $this->components->info("\"{$campaign->name}\" is running for {$seat->label()}.");

        return self::SUCCESS;
    }
}
