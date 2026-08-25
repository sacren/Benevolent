<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Authorization\OperatorRole;
use App\Models\Tenant;
use App\Models\User;
use App\Operators\InviteOperator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Where a campaign's first Owner comes from (D-53, Axis 3).
 *
 * **The platform provisions a campaign and the campaign governs it, and they
 * are not the same person.** `campaign:create` is something the platform runs:
 * it makes a database and a domain, never an identity. So the first Owner has
 * to be handed authority by the platform, and this command hands it the one
 * way that never lets the platform hold it: an invitation mailed to that
 * person, accepted by them, with a password they choose. The platform never
 * knows it. Creating the Owner directly would need a password somebody at the
 * platform typed or received, and that is the other shape Axis 3 named, and
 * the worse one.
 *
 * **Only while the campaign has nobody at all.** That is exactly the condition
 * under which open registration used to hand out Owner to whoever arrived
 * first, and this replaces that power rather than extending it: once a
 * campaign has an operator, admitting anybody else is the campaign's own act
 * (OperatorInvitationController), not the platform's. A campaign whose
 * operators have all left is empty again and can be given a first Owner the
 * same way. A campaign with operators and no Owner is a different problem,
 * and the application no longer produces one: CampaignGovernance refuses the
 * last governor's departure while anybody stays, and withdraws a departing
 * operator's unused invitations so none can be accepted into an empty
 * campaign afterwards. One left that way before Phase 6 Step 4 is not this
 * command's to reach into either.
 *
 * **Addressed by slug**, for the reason `campaign:contact` gives. It serves a
 * campaign provisioned today and one provisioned before this command existed
 * and never claimed, which is the second case D-53 said the step must answer.
 *
 * **The link is mailed and never printed.** Printing it would leave a live
 * credential to a campaign's governance in the platform operator's terminal
 * scrollback and shell history, which is the platform holding the authority
 * this command exists to hand over. The mail goes out in the command, not
 * queued, for the reason every invitation does (D-56).
 */
#[Signature('campaign:invite-owner {slug : The campaign slug} {email : The address of the person who will govern it}')]
#[Description("Invite a campaign's first Owner, while it has no operators at all.")]
class InviteCampaignOwner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InviteOperator $invite): int
    {
        $slug = (string) $this->argument('slug');
        $email = (string) $this->argument('email');

        $campaign = Tenant::query()->where('slug', $slug)->first();

        if ($campaign === null) {
            $this->components->error("No campaign with the slug \"{$slug}\".");

            return self::FAILURE;
        }

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->components->error("\"{$email}\" is not a usable email address.");

            return self::FAILURE;
        }

        tenancy()->initialize($campaign);

        try {
            if (User::query()->exists()) {
                $this->components->error("\"{$campaign->name}\" already has operators. Its Owners invite anybody else from the campaign itself.");

                return self::FAILURE;
            }

            $refusal = InviteOperator::refusalFor($email);

            if ($refusal !== null) {
                $this->components->error($refusal);

                return self::FAILURE;
            }

            try {
                // On nobody's recorded authority, which is what the nullable
                // inviter columns were left nullable for (D-54).
                $invite($email, OperatorRole::Owner, null);
            } catch (TransportExceptionInterface) {
                $this->components->error("The invitation to {$email} could not be sent, so none was recorded.");

                return self::FAILURE;
            }

            $this->components->info("Invitation sent to {$email}. Accepting it makes them the first Owner of \"{$campaign->name}\".");

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
