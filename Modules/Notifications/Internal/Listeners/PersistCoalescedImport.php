<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class PersistCoalescedImport
{
    // Wire-format keys the batch event carries when every row
    // originated from an email receipt.
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
                $this->writer->write(new NotificationDraft(
                    userId: $event->userId,
                    triggerType: DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
                    subjectKey: 'import',
                    occurrence: $occurrence,
                    title: NotificationCopy::TITLE_RECEIPTS,
                    body: $this->pluralCount($event->insertedCount, 'receipt').' matched from your email.',
                    params: ['target_kind' => 'inbox'],
                    deepLinkRoute: $this->urls->route('inboxes.index'),
                ));

                return;
            }

            $this->writer->write(new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
                subjectKey: 'import',
                occurrence: $occurrence,
                title: NotificationCopy::TITLE_IMPORT_FINISHED,
                body: $this->pluralCount($event->insertedCount, 'transaction').' imported.',
                params: ['target_kind' => 'import'],
                deepLinkRoute: $this->urls->route('imports.new'),
            ));
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating import.
            $this->log->error('PersistCoalescedImport: failed to persist import notification', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
                'insertedCount' => $event->insertedCount,
                'sourceFormats' => $event->sourceFormats,
            ]);
        }
    }

    // "1 transaction" / "37 transactions" - a simple regular-plural
    // suffix, sufficient for the two nouns this listener ever formats.
    private function pluralCount(int $count, string $noun): string
    {
        return $count === 1 ? "{$count} {$noun}" : "{$count} {$noun}s";
    }
}
