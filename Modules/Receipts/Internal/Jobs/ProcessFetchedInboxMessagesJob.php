<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// Per-user hourly consumer of inbox_messages rows with status='fetched'.
// Walks the InboxMessageQuery generator, resolves each row's on-disk
// .eml bytes, runs RecordReceipt, and bridges a parsed outcome into
// the canonical import pipeline.
final class ProcessFetchedInboxMessagesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        Clock $clock,
        Filesystem $files,
        InboxMessageQuery $inboxes,
        EmlBlobStore $blobs,
        RecordReceipt $recordReceipt,
        ReceiptLedgerBridge $bridge,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        // Created lazily on the first parsed receipt so an empty
        // backlog walk never creates an orphan ImportRun row.
        $importRunId = null;

        foreach ($inboxes->forStatus(InboxMessageStatus::Fetched->value) as $dto) {
            if ($dto->userId !== $this->userId) {
                // Defence-in-depth: the query is user-agnostic by
                // design; the consumer enforces the per-user scope.
                continue;
            }

            $emlPath = $blobs->pathFor(
                userId: $dto->userId,
                inboxId: $dto->inboxId,
                internalDate: $dto->internalDate,
                providerMessageId: $dto->providerMessageId,
            );
            if (! $files->exists($emlPath)) {
                // Missing blob: surface as unmatched so the user can
                // re-fetch rather than tripping a phantom-orphan sweep.
                $this->markUnmatched($db, $clock, $dto->id);

                continue;
            }

            $outcome = ($recordReceipt)($files->get($emlPath), $user, null);
            [$update, $importRunId] = $this->resolveUpdate($outcome, $user, $importRunId, $clock, $bridge);

            $db->connection()
                ->table('inbox_messages')
                ->where('id', $dto->id)
                ->update($update);
        }
    }

    private function markUnmatched(DatabaseManager $db, Clock $clock, int $inboxMessageId): void
    {
        $db->connection()
            ->table('inbox_messages')
            ->where('id', $inboxMessageId)
            ->update([
                'status' => InboxMessageStatus::Unmatched->value,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);
    }

    /**
     * @return array{array<string, mixed>, ?int}
     */
    private function resolveUpdate(
        MatchOutcomeDto $outcome,
        User $user,
        ?int $importRunId,
        Clock $clock,
        ReceiptLedgerBridge $bridge,
    ): array {
        $update = [
            'updated_at' => $clock->now()->toDateTimeString(),
            'status' => $outcome->kind->toInboxStatus()->value,
        ];

        if ($outcome->kind !== MatchOutcomeKind::Parsed || $outcome->parsed === null) {
            return [$update, $importRunId];
        }

        $update['matcher_key'] = $outcome->matcherKey;
        $importRunId = $bridge->bridge($outcome->parsed, $user, $importRunId, SourceFormat::Eml);

        return [$update, $importRunId];
    }
}
