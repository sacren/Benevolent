<?php

declare(strict_types=1);

namespace App\MailOutcomes;

use App\Models\BlastRecipient;
use App\Models\ProviderMessage;
use App\Models\Supporter;
use App\Models\Tenant;
use App\Supporters\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 * **Two notices change the supporter as well, and only from Subscribed
 * (D-87).** A Permanent bounce makes them Bounced, since the address is known
 * not to take mail; a complaint makes them Unsubscribed, since it is the
 * recipient asking for no more, and it writes no `unsubscribes` row, because
 * that table counts links used. Somebody already Unsubscribed or Bounced stays
 * as they are. The supporter changes only when the copy did, so a duplicate
 * notice cannot undo an operator who has since put them back; and both writes
 * share one transaction, so a refused supporter write leaves the copy unwritten
 * for SNS's retry to find, rather than written and skipped for ever.
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
            DB::transaction(function () use ($providerMessageId, $outcome): void {
                $copy = BlastRecipient::query()->where('provider_message_id', $providerMessageId);

                $changed = $outcome['onlyIf']($copy)->update($outcome['write']);

                if ($changed === 0 || $outcome['supporterBecomes'] === null) {
                    return;
                }

                Supporter::query()
                    ->whereIn('id', BlastRecipient::query()->select('supporter_id')->where('provider_message_id', $providerMessageId))
                    ->where('subscription_status', SubscriptionStatus::Subscribed)
                    ->update(['subscription_status' => $outcome['supporterBecomes']->value]);
            });
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
     * it is empty. `supporterBecomes` is what a Subscribed supporter is moved to,
     * or null where the notice says nothing about whether they may be written to.
     *
     * @param  array<string, mixed>  $notice
     * @return array{write: array<string, mixed>, onlyIf: callable(Builder<BlastRecipient>): Builder<BlastRecipient>, supporterBecomes: ?SubscriptionStatus}|null
     */
    private function outcomeFrom(array $notice): ?array
    {
        $type = data_get($notice, 'notificationType');

        if ($type === 'Delivery') {
            $at = $this->timestampOf($notice, 'delivery.timestamp');

            return $at === null ? null : [
                'write' => ['delivered_at' => $at],
                'onlyIf' => fn (Builder $copy): Builder => $copy->whereNull('delivered_at'),
                'supporterBecomes' => null,
            ];
        }

        if ($type === 'Complaint') {
            $at = $this->timestampOf($notice, 'complaint.timestamp');

            return $at === null ? null : [
                'write' => ['complained_at' => $at],
                'onlyIf' => fn (Builder $copy): Builder => $copy->whereNull('complained_at'),
                'supporterBecomes' => SubscriptionStatus::Unsubscribed,
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
                'supporterBecomes' => $bounceType === BounceType::Permanent ? SubscriptionStatus::Bounced : null,
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
