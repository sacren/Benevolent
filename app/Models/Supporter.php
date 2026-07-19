<?php

declare(strict_types=1);

namespace App\Models;

use App\Supporters\SubscriptionStatus;
use Database\Factories\SupporterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone a campaign is trying to reach.
 *
 * Sits in app/Models/ while the vocabulary it speaks lives in app/Supporters/,
 * the same split this application already makes twice over — OperatorRole and
 * Permission in app/Authorization/, AuditEvent in app/Audit/, the models they
 * describe here. Eloquent models are where the framework and every convention
 * look for them; the domain's words follow the module.
 *
 * **This model deliberately names no connection.** That is not an omission: it
 * means a supporter follows the default connection, which tenancy has already
 * switched onto the campaign serving the request, so a campaign's list lands in
 * the campaign's own database. Naming a connection here — central, most
 * plausibly, since "the supporter list" sounds like platform data — would pool
 * every campaign's supporters into one table and hand any reader another
 * campaign's people. AuditEntry says the same thing from the other side, and
 * the migration says it from the schema's.
 *
 * The name columns carry an invariant worth stating where it can be read:
 * `name` is current truth, and `given_name` and `family_name` are provenance —
 * what the source told us. Nothing derives one from the other, nothing splits a
 * name, and nothing edits the parts in this phase. With one writer for the
 * parts and one for the display string, the two cannot disagree by accident.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $given_name
 * @property string|null $family_name
 * @property string $email
 * @property string|null $postcode
 * @property SubscriptionStatus $subscription_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'given_name', 'family_name', 'email', 'postcode', 'subscription_status'])]
class Supporter extends Model
{
    /** @use HasFactory<SupporterFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscription_status' => SubscriptionStatus::class,
        ];
    }
}
