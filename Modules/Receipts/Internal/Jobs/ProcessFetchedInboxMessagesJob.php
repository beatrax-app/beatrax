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

/**
 * Per-user hourly consumer of `inbox_messages.status='fetched'` rows.
 *
 * Walks the InboxMessageQuery generator for the bound user, resolves
 * each row's on-disk `.eml` bytes via EmlBlobStore, invokes
 * RecordReceipt against the bytes, and lets that action transition
 * the row to `parsed` / `skipped` / `unmatched` via the shared
 * matcher pipeline.
 *
 * Concurrency contract (mirrors ResolveChainLinksJob):
 *  - `ShouldBeUniqueUntilProcessing` keyed on `uniqueId() = userId`
 *    blocks a second per-user dispatch while a prior pass is still
 *    queued — the lock releases the moment a worker begins handle().
 *  - `tries = 3` + `backoff = [60, 300, 900]` per the project-wide
 *    queued-job convention.
 *  - `uniqueFor = 600` (10 minutes) — long enough to cover a healthy
 *    backlog walk, short enough to unblock if a worker crashes
 *    mid-handle.
 *
 * Cross-user defence: the consumer also re-checks each yielded DTO's
 * `userId` against `$this->userId` and skips mismatches, even though
 * the InboxMessageQuery binding is user-agnostic by design (the user
 * filter happens in the consumer, not the query). The
 * defence-in-depth comparison prevents a future query-side refactor
 * from silently widening the per-user surface.
 *
 * Queue-uniqueness lock resolution is delegated to the shared
 * `Modules\Core\Public\Support\LockStore` helper: `uniqueVia()`
 * returns `LockStore::forUniqueJobs()`, which resolves the cache store
 * named by `config('cache.locks_store')`.
 *
 * The matcher dispatch flow inside handle() is synchronous and pure
 * — the queue layer is just a per-user backlog walker on a known
 * cadence (hourly, matched to the EmailScan incremental scan
 * rhythm).
 */
final class ProcessFetchedInboxMessagesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry attempts before final failure. */
    public int $tries = 3;

    /**
     * Exponential backoff in seconds.
     *
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

        // Create a per-job ImportRun row so canonical writes from
        // parsed receipts have a valid `import_run_id` FK to attach
        // to. The row's source_format is the literal 'inbox-handoff'
        // so the audit trail distinguishes inbox-handoff writes from
        // wizard-driven imports. We persist on first parsed receipt
        // only so empty backlog walks do not create orphan rows.
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
                // Missing blob: surface the row as unmatched so the
                // user can re-fetch the .eml from the inbox without
                // tripping the cleanup pass on a phantom orphan.
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

            // RecordReceipt writes a file_imports row + runs the
            // matcher. Its status transition lives on the
            // file_imports table; we also mirror the lifecycle onto
            // the inbox_messages row so the /inboxes drawer can
            // surface the matcher outcome alongside the fetch
            // timestamp.
            $outcome = ($recordReceipt)($bytes, $user, null);

            $update = [
                'updated_at' => $clock->now()->toDateTimeString(),
            ];

            if ($outcome->kind === 'parsed' && $outcome->parsed !== null) {
                $rawKey = $outcome->parsed->rawPayload['matcher_key'] ?? null;
                $update['status'] = 'parsed';
                $update['matcher_key'] = is_string($rawKey) && $rawKey !== '' ? $rawKey : null;

                // Bridge the parsed receipt into the canonical pipeline
                // so the consumer side mirrors the wizard's writes. The
                // synthetic per-provider IBAN (`PAYPAL`, `ICS-CARD`,
                // `GOOGLE-PLAY`) resolves to the user's matching
                // Account row; absent rows skip the canonical write
                // (the file_imports lifecycle already recorded the
                // parse outcome so a future Account creation can
                // re-trigger the canonical write via the standard
                // import path).
                $account = Account::query()
                    ->where('user_id', $user->id)
                    ->where('iban', $outcome->parsed->ownIban)
                    ->first();
                if ($account !== null) {
                    if ($importRunId === null) {
                        // Synthetic sentinel for the raw_file_path
                        // column: inbox-handoff writes do not have a
                        // file on disk, so the audit trail uses a
                        // discoverable marker rather than an empty
                        // string (which would conflate with corrupt
                        // / never-attached values in future audit
                        // queries).
                        $rawPathSentinel = '__INBOX_HANDOFF__/user-'.$this->userId.'/'.$clock->now()->format('Y-m-d');
                        // sha256 must remain deterministic per import
                        // run (the column is otherwise the content
                        // hash); we mix in a stable per-run anchor
                        // (the wall-clock day plus userId) so two
                        // hourly runs on the same day collapse to one
                        // ImportRun rather than diverging on the
                        // sub-second clock.
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
