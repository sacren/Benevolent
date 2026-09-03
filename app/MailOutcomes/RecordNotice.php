<?php

declare(strict_types=1);

namespace App\MailOutcomes;

use App\Models\BlastRecipient;
use App\Models\ProviderMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Write what one verified SES notice says onto the copy it names (D-86, D-87).
 *
 * **The copy is found by the provider's message id and nothing else.** The id
 * resolves to one campaign in the central table, and to one copy inside it, so
 * a notice for one campaign's copy cannot land in another campaign that holds
 * the same address, and a notice for the earlier of two copies to one person
 * lands on that copy rather than the latest. Joining by the recipient address
 * would get both wrong.
 *
 * **Exactly one campaign is entered, and it is always left.** The campaign
 * comes from the central row, so a notice never walks the others (criterion
 * 7). Tenancy is ended in a `finally` rather than by stancl's `Tenant::run()`,
 * because `run()` ends it only when the callback returns: an exception would
 * leave a central request connected to a campaign's database (D-88).
 *
 * **Anything that names nothing the product kept writes nothing, and says
 * nothing (D-89).** An id with no central row (an invitation, account mail,
 * mail sent before the intake existed), a campaign since deleted, a copy whose
 * reader was erased, a duplicate, a notification type that is not one of the
 * three: each returns without a write and without a log line, since a logged
 * id is a join key to an address in the provider's records.
 *
 * **A refused write is rethrown without the database's words.** A
 * QueryException prints its SQL with the id inlined, and the exception
 * handler would log it; what survives is the SQLSTATE, the same shape the
 * send path uses. The request then answers 5xx, so SNS retries.
 */
final class RecordNotice
{
    /**
     * @param  array<string, mixed>  $notice  The decoded `Message` of a verified SNS Notification.
     */
    public function __invoke(array $notice): void
    {
        $providerMessageId = data_get($notice, 'mail.messageId');

        if (! is_string($providerMessageId) || $providerMessageId === '') {
            return;
        }

        $outcome = $this->outcomeFrom($notice);

        if ($outcome === null) {
            return;
        }

        $tenantId = ProviderMessage::query()->whereKey($providerMessageId)->value('tenant_id');
        $tenant = is_string($tenantId) ? Tenant::query()->find($tenantId) : null;

        if (! $tenant instanceof Tenant) {
            return;
        }

        tenancy()->initialize($tenant);

        try {
            $copy = BlastRecipient::query()->where('provider_message_id', $providerMessageId);

            $outcome['onlyIf']($copy)->update($outcome['write']);
        } catch (QueryException $exception) {
            throw new RuntimeException(sprintf(
                'A provider notice could not be recorded: the database refused the write (SQLSTATE %s).',
                $exception->getCode(),
            ));
        } finally {
            tenancy()->end();
        }
    }

    /**
     * What this notice writes, and the condition that makes it set-once.
     *
     * A Permanent bounce may replace a Transient or Undetermined one, because
     * it is the only bounce that says something about the address; nothing
     * replaces a Permanent one, and every other column is written only while
     * it is empty.
     *
     * @param  array<string, mixed>  $notice
     * @return array{write: array<string, mixed>, onlyIf: callable(Builder<BlastRecipient>): Builder<BlastRecipient>}|null
     */
    private function outcomeFrom(array $notice): ?array
    {
        $type = data_get($notice, 'notificationType');

        if ($type === 'Delivery') {
            $at = $this->timestampOf($notice, 'delivery.timestamp');

            return $at === null ? null : [
                'write' => ['delivered_at' => $at],
                'onlyIf' => fn (Builder $copy): Builder => $copy->whereNull('delivered_at'),
            ];
        }

        if ($type === 'Complaint') {
            $at = $this->timestampOf($notice, 'complaint.timestamp');

            return $at === null ? null : [
                'write' => ['complained_at' => $at],
                'onlyIf' => fn (Builder $copy): Builder => $copy->whereNull('complained_at'),
            ];
        }

        if ($type === 'Bounce') {
            $at = $this->timestampOf($notice, 'bounce.timestamp');
            $bounceType = BounceType::tryFrom((string) data_get($notice, 'bounce.bounceType')) ?? BounceType::Undetermined;

            return $at === null ? null : [
                'write' => ['bounced_at' => $at, 'bounce_type' => $bounceType->value],
                'onlyIf' => $bounceType === BounceType::Permanent
                    ? fn (Builder $copy): Builder => $copy->where(fn (Builder $empty): Builder => $empty
                        ->whereNull('bounced_at')
                        ->orWhere('bounce_type', '!=', BounceType::Permanent->value))
                    : fn (Builder $copy): Builder => $copy->whereNull('bounced_at'),
            ];
        }

        return null;
    }

    /**
     * The provider's own time for the event, or nothing if it gave none.
     *
     * @param  array<string, mixed>  $notice
     */
    private function timestampOf(array $notice, string $path): ?Carbon
    {
        $value = data_get($notice, $path);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
