<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Psr\Log\LoggerInterface;
use Throwable;

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
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(TransactionBatchImported $event): void
    {
        try {
            $isReceipts = $event->sourceFormats !== []
                && array_diff($event->sourceFormats, self::RECEIPT_FORMATS) === [];

            $occurrence = $this->clock->now()->format('Y-m-d H:i:s').':'.$event->insertedCount;

            if ($isReceipts) {
                $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => new NotificationDraft(
                    userId: $event->userId,
                    triggerType: DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
                    subjectKey: 'import',
                    occurrence: $occurrence,
                    title: Lang::get('notifications::copy.title.receipts'),
                    body: Lang::choice('notifications::copy.body.receipts_matched', $event->insertedCount, ['count' => $event->insertedCount]),
                    params: ['target_kind' => 'inbox'],
                    deepLinkRoute: $this->urls->route('inboxes.index'),
                ));
                $this->writer->write($draft);

                return;
            }

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => new NotificationDraft(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
                subjectKey: 'import',
                occurrence: $occurrence,
                title: Lang::get('notifications::copy.title.import_finished'),
                body: Lang::choice('notifications::copy.body.import_finished', $event->insertedCount, ['count' => $event->insertedCount]),
                params: ['target_kind' => 'import'],
                deepLinkRoute: $this->urls->route('imports.new'),
            ));
            $this->writer->write($draft);
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
}
