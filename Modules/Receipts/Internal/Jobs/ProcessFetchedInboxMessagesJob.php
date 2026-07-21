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
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

// Per-user hourly consumer of inbox_messages rows with status='fetched'.
// Walks the InboxMessageQuery generator, resolves each row's on-disk
// .eml bytes, runs RecordReceipt, and bridges a parsed outcome into
// the canonical import pipeline.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class ProcessFetchedInboxMessagesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

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
                $db->connection()
                    ->table('inbox_messages')
                    ->where('id', $dto->id)
                    ->update([
                        'status' => 'unmatched',
                        'updated_at' => $clock->now()->toDateTimeString(),
                    ]);

                continue;
            }

            $bytes = $files->get($emlPath);

            $outcome = ($recordReceipt)($bytes, $user, null);

            $update = [
                'updated_at' => $clock->now()->toDateTimeString(),
            ];

            if ($outcome->kind === 'parsed' && $outcome->parsed !== null) {
                $rawKey = $outcome->parsed->rawPayload['matcher_key'] ?? null;
                $update['status'] = 'parsed';
                $update['matcher_key'] = is_string($rawKey) && $rawKey !== '' ? $rawKey : null;

                // Bridge the parsed receipt into the canonical pipeline;
                // the synthetic per-provider IBAN resolves to the
                // user's matching Account, absent which the write is
                // skipped (the file_imports row still records the parse).
                $account = Account::query()
                    ->where('user_id', $user->id)
                    ->where('iban', $outcome->parsed->ownIban)
                    ->first();
                if ($account !== null) {
                    if ($importRunId === null) {
                        // Sentinel path for raw_file_path — inbox-handoff
                        // writes have no on-disk source file.
                        $rawPathSentinel = '__INBOX_HANDOFF__/user-'.$this->userId.'/'.$clock->now()->format('Y-m-d-H');
                        // The sha256 anchor is stable per user+hour so
                        // two runs in the same hour collapse to one
                        // ImportRun rather than diverging on sub-second
                        // clock drift.
                        $runAnchor = sprintf(
                            'inbox-handoff:%d:%s',
                            $this->userId,
                            $clock->now()->format('Y-m-d-H'),
                        );
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
                        $importRunId = $newRun->id;
                    }
                    $source = $receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: 0);
                    $canonical = $normalize->run($source, $account->id, $user, importRunId: $importRunId, sourceFormat: 'eml');
                    ($recorder)([$canonical], $user);
                }
            } elseif ($outcome->kind === 'skipped') {
                $update['status'] = 'skipped';
            } else {
                $update['status'] = 'unmatched';
            }

            $db->connection()
                ->table('inbox_messages')
                ->where('id', $dto->id)
                ->update($update);
        }
    }
}
