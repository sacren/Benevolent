<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\CampaignContact;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Shows or sets the address a campaign's mail comes back to.
 *
 * `campaign:create --contact=` covers a campaign being provisioned. This covers
 * the two cases that command cannot: a campaign that already exists and has
 * never had one, and a campaign whose address has changed. Both are ordinary --
 * an address outlives nobody in particular, and the campaigns that predate this
 * value have no other way to acquire one.
 *
 * Addressed by slug rather than by name, because the slug is what identifies a
 * campaign everywhere else a person types one, and it is what `campaign:create`
 * prints back.
 *
 * **No way to clear one, deliberately.** Removing an address would leave a
 * campaign mailing its supporters with no reply path, which is a thing to do on
 * purpose rather than a thing to make easy, and nothing has asked for it.
 * Trigger to revisit: the first campaign that needs to stop publishing an
 * address it has published.
 */
#[Signature('campaign:contact {slug : The campaign slug} {address? : The reply address; omit to show the current one}')]
#[Description("Show or set the address a campaign's mail should come back to.")]
class SetCampaignContact extends Command
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

        $address = $this->argument('address');

        if (! is_string($address)) {
            // Read from the registry row rather than through
            // CampaignContact::address(), which answers for whichever campaign
            // is *active* -- and none is, since this command runs centrally.
            // Asking the campaign named on the command line is the only
            // question a reader of this line could mean.
            $stored = $campaign->getAttribute(CampaignContact::KEY);

            $this->components->info(is_string($stored) && trim($stored) !== ''
                ? "\"{$campaign->name}\" replies come back to {$stored}."
                : "\"{$campaign->name}\" has no reply address, so its blasts cannot be answered.");

            return self::SUCCESS;
        }

        if (! CampaignContact::usable($address)) {
            $this->components->error("\"{$address}\" is not a usable reply address.");

            return self::FAILURE;
        }

        CampaignContact::store($campaign, $address);

        $this->components->info("\"{$campaign->name}\" replies now come back to {$address}.");

        return self::SUCCESS;
    }
}
