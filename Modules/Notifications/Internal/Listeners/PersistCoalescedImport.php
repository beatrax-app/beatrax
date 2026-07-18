<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists Req 10's coalesced import notification into the unified inbox —
 * ONE row per import batch, never one per row (D-22).
 *
 * Subscribes to `Modules\Ledger\Public\Events\TransactionBatchImported` —
 * registered by `NotificationsServiceProvider`'s guarded listener table
 * (18-05); this class's exact name/namespace is what flips that guard on.
 *
 * Owns the receipts-vs-import split that
 * `Modules\Desktop\Internal\Listeners\DispatchOsNotification::
 * handleTransactionImported()` performs today, moved to the new batch
 * altitude: routes to the receipts copy iff EVERY entry in
 * `$event->sourceFormats` is in `RECEIPT_FORMATS`; otherwise routes to the
 * import-finished copy. A mixed batch is therefore total, never a coin flip.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `SyncCaptureListener::handle()`: a failed notification-persist
 * must NEVER break the originating import.
 *
 * ## Occurrence key — deliberately NOT a date (D-06 divergence)
 *
 * Every other reactive/proactive trigger in this phase keys its occurrence
 * on a stable, re-derivable identity (a date, a period) so the SAME logical
 * fact computed independently on two devices converges on one row. An
 * import is different: it is a local, user-initiated event that is not
 * independently re-derived on a second device, so cross-device convergence
 * does not apply to it — it only needs to not fire twice for one batch.
 * The occurrence is therefore the batch's own completion timestamp (at
 * second precision) plus its inserted count: unique enough to distinguish
 * two separate imports landing in the same second with different counts,
 * while a genuinely duplicate dispatch of the SAME batch (same second, same
 * count) derives the same id and is silently absorbed by
 * `NotificationWriter`'s `insertOrIgnore`.
 */
final class PersistCoalescedImport
{
    /** Wire-format keys the batch event carries when every row originated from an email receipt. */
    private const RECEIPT_FORMATS = ['eml', 'mbox'];

    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(TransactionBatchImported $event): void
    {
        try {
            $isReceipts = $event->sourceFormats !== []
                && array_diff($event->sourceFormats, self::RECEIPT_FORMATS) === [];

            $occurrence = $this->clock->now()->format('Y-m-d H:i:s').':'.$event->insertedCount;

            if ($isReceipts) {
                $this->writer->write(
                    userId: $event->userId,
                    triggerType: DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
                    subjectKey: 'import',
                    occurrence: $occurrence,
                    title: NotificationCopy::TITLE_RECEIPTS,
                    body: $this->pluralCount($event->insertedCount, 'receipt').' matched from your email.',
                    params: ['target_kind' => 'inbox'],
                    deepLinkRoute: $this->urls->route('inboxes.index'),
                );

                return;
            }

            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
                subjectKey: 'import',
                occurrence: $occurrence,
                title: NotificationCopy::TITLE_IMPORT_FINISHED,
                body: $this->pluralCount($event->insertedCount, 'transaction').' imported.',
                params: ['target_kind' => 'import'],
                deepLinkRoute: $this->urls->route('imports.new'),
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // import (D-07).
            $this->log->error('PersistCoalescedImport: failed to persist import notification', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
                'insertedCount' => $event->insertedCount,
                'sourceFormats' => $event->sourceFormats,
            ]);
        }
    }

    /** "1 transaction" / "37 transactions" — a simple regular-plural suffix, sufficient for the two nouns this listener ever formats. */
    private function pluralCount(int $count, string $noun): string
    {
        return $count === 1 ? "{$count} {$noun}" : "{$count} {$noun}s";
    }
}
