<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Migration\Public\Support\MigrationSourceFormat;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistCoalescedImport
{
    public function __construct(
        private NotificationWriter $writer,
        private UrlGenerator $urls,
        private Clock $clock,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(TransactionBatchImported $event): void
    {
        try {
            [$trigger, $titleKey, $bodyKey, $targetKind, $deepLinkRoute] = $this->announcement($event);

            $occurrence = $this->clock->now()->format('Y-m-d H:i:s').':'.$event->insertedCount;

            $copy = NotificationCopySpec::of(
                CopyLine::of($titleKey),
                CopyLine::plural($bodyKey, $event->insertedCount),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: $trigger,
                subjectKey: 'import',
                occurrence: $occurrence,
                copy: $copy,
                params: ['target_kind' => $targetKind],
                deepLinkRoute: $deepLinkRoute,
            ));

            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating import.
            $this->log->error('PersistCoalescedImport: failed to persist import notification', [
                ...SafeExceptionContext::describe($e),
                'userId' => $event->userId,
                'insertedCount' => $event->insertedCount,
                'sourceFormats' => $event->sourceFormats,
            ]);
        }
    }

    // Which of the four the batch was, read off the formats the ledger
    // recorded: a hand-typed cash entry and a migrated budget both travel the
    // same canonical pipeline an import does, and were announced as one. Mixed
    // formats fall to the import arm: not wholly one thing is an import.
    /**
     * @return array{0: NotificationTrigger, 1: string, 2: string, 3: string, 4: string}
     */
    private function announcement(TransactionBatchImported $event): array
    {
        // Ordered, not exclusive: a batch wholly of receipts is also wholly
        // imported, and the first arm that matches is the one that names it.
        return match (true) {
            self::wholly($event, SourceFormat::receiptFormats()) => [
                NotificationTrigger::ReceiptsFound,
                'notifications::copy.title.receipts',
                'notifications::copy.body.receipts_matched',
                'inbox',
                Destination::Email->urlFrom($this->urls),
            ],
            self::wholly($event, [SyntheticSourceFormat::Manual->value]) => [
                NotificationTrigger::ManualEntryRecorded,
                'notifications::copy.title.manual_entry',
                'notifications::copy.body.manual_entry',
                'cash-book',
                Destination::CashBook->urlFrom($this->urls),
            ],
            self::whollyMigrated($event) => [
                NotificationTrigger::MigrationFinished,
                'notifications::copy.title.migration_finished',
                'notifications::copy.body.migration_finished',
                'migration',
                // Migrating is a once-ever errand and holds no sidebar row, so
                // /migrations is not a Destination. DeepLinkResolver names the
                // same route when it re-derives the reader's own row.
                $this->urls->route('migrations.index'),
            ],
            default => [
                NotificationTrigger::ImportFinished,
                'notifications::copy.title.import_finished',
                'notifications::copy.body.import_finished',
                'import',
                Destination::Imports->urlFrom($this->urls),
            ],
        };
    }

    /**
     * @param  list<string>  $formats
     */
    private static function wholly(TransactionBatchImported $event, array $formats): bool
    {
        return $event->sourceFormats !== [] && array_diff($event->sourceFormats, $formats) === [];
    }

    // The product rides in the value, so the set the other arms match against
    // cannot be written down: every migration format is one comparison.
    private static function whollyMigrated(TransactionBatchImported $event): bool
    {
        foreach ($event->sourceFormats as $format) {
            if (! MigrationSourceFormat::names($format)) {
                return false;
            }
        }

        return $event->sourceFormats !== [];
    }
}
