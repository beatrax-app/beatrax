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
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

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
        ReceiptSourceAdapter $receiptAdapter,
        NormalizeStage $normalize,
        RecordsTransactions $recorder,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        // Created lazily on the first parsed receipt so an empty
        // backlog walk never creates an orphan ImportRun row.
        $importRunId = null;

        foreach ($inboxes->forStatus('fetched') as $dto) {
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
            [$update, $importRunId] = $this->resolveUpdate(
                $outcome,
                $user,
                $importRunId,
                $clock,
                $receiptAdapter,
                $normalize,
                $recorder,
            );

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
                'status' => 'unmatched',
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
        ReceiptSourceAdapter $receiptAdapter,
        NormalizeStage $normalize,
        RecordsTransactions $recorder,
    ): array {
        $update = ['updated_at' => $clock->now()->toDateTimeString()];

        if ($outcome->kind === 'parsed' && $outcome->parsed !== null) {
            $rawKey = $outcome->parsed->rawPayload['matcher_key'] ?? null;
            $update['status'] = 'parsed';
            $update['matcher_key'] = is_string($rawKey) && $rawKey !== '' ? $rawKey : null;
            $importRunId = $this->bridgeToLedger(
                $outcome->parsed,
                $user,
                $importRunId,
                $clock,
                $receiptAdapter,
                $normalize,
                $recorder,
            );

            return [$update, $importRunId];
        }

        $update['status'] = $outcome->kind === 'skipped' ? 'skipped' : 'unmatched';

        return [$update, $importRunId];
    }

    // Bridge the parsed receipt into the canonical pipeline; the
    // synthetic per-provider IBAN resolves to the user's matching
    // Account, absent which the write is skipped (the file_imports row
    // still records the parse). Returns the ImportRun id in play.
    private function bridgeToLedger(
        ParsedReceiptDto $parsed,
        User $user,
        ?int $importRunId,
        Clock $clock,
        ReceiptSourceAdapter $receiptAdapter,
        NormalizeStage $normalize,
        RecordsTransactions $recorder,
    ): ?int {
        $account = Account::query()
            ->where('user_id', $user->id)
            ->where('iban', $parsed->ownIban)
            ->first();
        if ($account === null) {
            return $importRunId;
        }

        $importRunId ??= $this->createInboxHandoffRun($user, $clock);
        $source = $receiptAdapter->toSourceDto($parsed, sourceRowIndex: 0);
        $canonical = $normalize->run($source, $account->id, $user, importRunId: $importRunId, sourceFormat: 'eml');
        ($recorder)([$canonical], $user);

        return $importRunId;
    }

    private function createInboxHandoffRun(User $user, Clock $clock): int
    {
        // Sentinel path for raw_file_path — inbox-handoff writes have no
        // on-disk source file. The sha256 anchor is stable per user+hour
        // so two runs in the same hour collapse to one ImportRun rather
        // than diverging on sub-second clock drift.
        $rawPathSentinel = '__INBOX_HANDOFF__/user-'.$this->userId.'/'.$clock->now()->format('Y-m-d-H');
        $runAnchor = sprintf('inbox-handoff:%d:%s', $this->userId, $clock->now()->format('Y-m-d-H'));

        $newRun = ImportRun::query()->create([
            'user_id' => $user->id,
            'source_format' => 'inbox-handoff',
            'raw_file_path' => $rawPathSentinel,
            'sha256' => hash('sha256', $runAnchor),
            'uploaded_at' => $clock->now()->toDateTimeString(),
            'status' => 'confirmed',
            'created_at' => $clock->now()->toDateTimeString(),
            'updated_at' => $clock->now()->toDateTimeString(),
        ]);

        return $newRun->id;
    }
}
