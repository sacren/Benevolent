<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The campaign a provider's message id belongs to (D-86).
 *
 * **This is the one model here that names the central connection, and it
 * does so for the reason every other model must not.** A notice from the
 * provider arrives on the central host with nothing but a message id, so the
 * id has to be resolvable before any campaign is known; a row written to the
 * campaign's own database could only be found by entering every campaign.
 * The trait is the one `Tenant` itself reaches the central database through,
 * so the row lands centrally even when it is written from inside a send.
 *
 * **It holds a key and a campaign, never a person.** What the id names inside
 * the campaign -- the copy, and through it the supporter -- is found there,
 * where an erasure can reach it.
 *
 * No `#[Fillable]`: nothing an operator submits reaches this table. The
 * sending path writes it, once per copy the provider accepted. No relation
 * to `Tenant` either, for the reason `BlastRecipient` gives for `blast()`:
 * nothing reads one yet.
 *
 * @property string $provider_message_id
 * @property string $tenant_id
 * @property Carbon $created_at
 */
#[Table(key: 'provider_message_id', keyType: 'string', incrementing: false)]
class ProviderMessage extends Model
{
    use CentralConnection;

    /**
     * Written once and never changed, so there is no `updated_at` to keep.
     */
    public const ?string UPDATED_AT = null;
}
