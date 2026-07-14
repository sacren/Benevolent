<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry in a campaign's audit trail: who changed what, and when.
 *
 * Sits in app/Models/ while the vocabulary it speaks lives in app/Audit/, the
 * same split this application already makes for the authorization spine —
 * OperatorRole and Permission in app/Authorization/, the User they describe in
 * app/Models/. Eloquent models are where the framework and every convention
 * look for them; the domain's words are what follow the concern.
 *
 * **This model deliberately names no connection.** That is not an omission: it
 * means the entry follows the default connection, which tenancy has already
 * switched onto the campaign serving the request, so a campaign's history lands
 * in the campaign's own database. Naming a connection here — central, most
 * plausibly, since an audit trail sounds like platform infrastructure — would
 * write every campaign's history into one shared table and hand any reader of
 * it another campaign's past. The migration explains the same thing from the
 * schema's side.
 *
 * @property int $id
 * @property AuditEvent $event
 * @property string $subject_type
 * @property int $subject_id
 * @property string $subject_label
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property array<string, array{from: mixed, to: mixed}>|null $changes
 * @property Carbon|null $created_at
 */
#[Fillable(['event', 'subject_type', 'subject_id', 'subject_label', 'actor_id', 'actor_label', 'changes'])]
class AuditEntry extends Model
{
    /**
     * An audit entry is a statement about a moment, so it is written once and
     * never revised. Telling Eloquent there is no updated_at is what lets the
     * table go without the column at all.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
