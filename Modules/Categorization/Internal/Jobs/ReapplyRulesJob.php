<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\LazyCollection;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * User-triggered re-apply-to-history job (Req 4, D-05) — walks a
 * user's NON-split transactions in chunks and re-runs
 * `RuleEngine::match()` + `RuleApplier::applyAtReapply()` against every
 * eligible row. `RuleApplier::applyAtReapply()` already guarantees
 * per-field manual-provenance preservation (D-04), write-only-on-change
 * idempotency, and dispatches one `TransactionMutated` per genuinely
 * changed field — this job's own job is purely the chunked walk plus
 * the two guards RuleApplier cannot apply on its own: reconciled/locked
 * rows and split transactions.
 *
 * **Split guard (T-13.4-23):** `whereNotExists(transaction_splits)`
 * excludes every split transaction from the walk entirely — a split
 * transaction's category/tax semantics are leg-owned (13.1 D-02/D-06a),
 * so re-apply never even reads a split parent, let alone its legs. This
 * mirrors `RuleEngineIgnoresSplitLegsTest`'s "never touch
 * transaction_splits" invariant.
 *
 * **Reconciled guard (T-13.4-22):** `TransactionStatusQuery::
 * reconciledIdsAmong()` is called ONCE per chunk (never per-row) so a
 * reconciled/locked transaction is skipped before `RuleEngine`/
 * `RuleApplier` ever see it.
 *
 * **hits_count is NOT touched here** (Pitfall 3) — that counter is
 * bumped only by `ApplyAutoCategoryStage` at import time; re-apply
 * counts matches via the cache progress payload instead.
 *
 * **Concurrency contract:** unlike `BackfillAnomaliesJob`'s
 * first-activation-only shape (`ShouldBeUniqueUntilProcessing` +
 * a persistent `whereNull(...)->update(...)` claim), this job is
 * re-triggerable on every user click — a plain `ShouldBeUnique`
 * collapses a double-click into a single queued run without any
 * persistent claim column; a fresh dispatch after a prior run
 * completes is a legitimate new pass, not a duplicate.
 *
 * **Progress (Plan 08):** a pollable payload is written to the cache
 * under `rule-reapply:{userId}` (no new synced table) after every
 * chunk, so the UI can show a live "N of M" readout and a completion
 * summary. The cache entry TTLs out on its own — no cleanup job
 * needed.
 *
 * **Module boundary:** this job reads `transactions` for the chunk
 * walk (an allowed cross-module read, mirroring
 * `BackfillAnomaliesJob`/`SafetyNetAnomalySweepJob`) but performs NO
 * `transactions` WRITE itself — every mutation is delegated to
 * `RuleApplier`, which in turn delegates to the Ledger Public actions
 * (Plan 04/05).
 *
 * **CR-04 (14.1-07):** `counterparty_name`/`description` are decrypted
 * via {@see SensitiveColumnCodec} before {@see RuleMatchInput} is
 * built, so substring conditions match plaintext even though the two
 * columns are ciphertext at rest under an encrypted user. Safe by
 * construction under `dispatchSync` (this job's only reachable
 * dispatch shape, per plan 04/D-03): the KEK is always the caller's
 * own request-context key. The {@see AppLockKeyService::release()}
 * check below is a defensive belt against a future re-introduction of
 * a queued/daemon dispatch origin (RESEARCH Pitfall 1) — if it ever
 * fires, decrypting would silently no-op and this run would silently
 * classify nothing, exactly the failure this plan exists to close.
 */
final class ReapplyRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Chunk size for the history walk AND the reconciled-batch filter. */
    private const CHUNK = 500;

    /**
     * Progress cache TTL in seconds — long enough to outlive the whole
     * run (including retries) plus a reasonable UI poll gap after
     * completion.
     */
    private const PROGRESS_TTL_SECONDS = 3600;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    /** Cache key Plan 08's polling UI reads the progress payload from. */
    public static function progressCacheKey(int $userId): string
    {
        return "rule-reapply:{$userId}";
    }

    public function handle(
        RuleEngine $engine,
        RuleApplier $applier,
        TransactionStatusQuery $statusQuery,
        DatabaseManager $db,
        Repository $cache,
        Clock $clock,
        LoggerInterface $logger,
        SensitiveColumnCodec $codec,
        Session $session,
        AppLockKeyService $appLockKeyService,
        EncryptionMigrationService $encryptionService,
    ): void {
        /** @var User|null $user */
        $user = User::query()->where('id', $this->userId)->first();
        if ($user === null) {
            return;
        }

        $connection = $db->connection();
        $userId = $this->userId;

        // WR-14: only warn about a missing KEK when the user has actually
        // enabled encryption. release() returns null for EVERY user who never
        // enrolled encryption (the default single-user state), so the old
        // unconditional warning fired on every re-apply for the common,
        // nothing-is-encrypted path — a false alarm. A missing KEK only
        // degrades matching when there IS encrypted content to decrypt.
        $hasKek = $appLockKeyService->release($session) !== null;
        if (! $hasKek && $encryptionService->isEnabled($userId)) {
            $logger->warning(
                'ReapplyRulesJob: encryption is enabled but no app-lock KEK is available for this run — counterparty_name/description will be matched using their stored values as-is (any genuinely-encrypted value will fail to substring-match).',
                ['user_id' => $userId],
            );
        }

        $nonSplitQuery = static fn (): Builder => $connection->table('transactions')
            ->where('transactions.user_id', $userId)
            ->whereNotExists(static function (Builder $sub): void {
                $sub->select($sub->raw(1))
                    ->from('transaction_splits')
                    ->whereColumn('transaction_splits.transaction_id', 'transactions.id');
            });

        $total = $nonSplitQuery()->count();

        $cacheKey = self::progressCacheKey($userId);

        $progress = [
            'status' => 'running',
            'checked' => 0,
            'total' => $total,
            'fields_updated' => 0,
            'transactions_updated' => 0,
            'reconciled_skipped' => 0,
            // WR-03: rows skipped because match()/applyAtReapply() threw
            // (e.g. a malformed date condition value CreateCategorizationRule/
            // UpdateCategorizationRule's normalizeCondition() didn't
            // reject) — surfaced so a run that silently skipped rows is
            // still visible in the polled progress payload, not just
            // logged.
            'rows_errored' => 0,
            'started_at' => $clock->now()->toIso8601String(),
            'finished_at' => null,
        ];
        $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);

        /** @var LazyCollection<int, stdClass> $rows */
        $rows = $nonSplitQuery()
            ->select(['transactions.id', 'counterparty_name', 'description', 'settled_amount_minor', 'posted_at'])
            ->orderBy('transactions.id')
            ->lazyById(self::CHUNK, 'transactions.id', 'id');

        $rows->chunk(self::CHUNK)->each(
            /** @param  LazyCollection<int, stdClass>  $chunk */
            function (LazyCollection $chunk) use (
                $engine,
                $applier,
                $statusQuery,
                $user,
                $userId,
                $cache,
                $cacheKey,
                $logger,
                $codec,
                $session,
                $hasKek,
                &$progress,
            ): void {
                $ids = [];
                foreach ($chunk as $row) {
                    if (is_numeric($row->id)) {
                        $ids[] = (int) $row->id;
                    }
                }

                $reconciledIds = $statusQuery->reconciledIdsAmong($userId, $ids);

                foreach ($chunk as $row) {
                    $transactionId = is_numeric($row->id) ? (int) $row->id : 0;
                    if ($transactionId <= 0) {
                        continue;
                    }

                    $progress['checked'] = $progress['checked'] + 1;

                    if (in_array($transactionId, $reconciledIds, true)) {
                        $progress['reconciled_skipped'] = $progress['reconciled_skipped'] + 1;

                        continue;
                    }

                    // WR-03: fail-open per row, mirroring
                    // ApplyAutoCategoryStage/RuleApplier's own "a rule
                    // error never blocks the whole pass" convention. A
                    // malformed condition value (e.g. a non-date string
                    // reaching RuleEngine::matchDate()'s
                    // CarbonImmutable::parse() call) would otherwise throw
                    // uncaught out of this chunk closure, failing the
                    // ENTIRE queued job (all remaining chunks too) instead
                    // of just skipping the one bad row.
                    try {
                        $input = self::matchInputFromRow($row, $codec, $session, $userId, $hasKek);
                        $matched = $engine->match($input, $user);
                        $changed = $applier->applyAtReapply($matched, $transactionId, $userId);
                    } catch (Throwable $e) {
                        $progress['rows_errored'] = $progress['rows_errored'] + 1;
                        $logger->warning('ReapplyRulesJob skipped a row after a match/apply failure.', [
                            'user_id' => $userId,
                            'transaction_id' => $transactionId,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    if ($changed !== []) {
                        $progress['transactions_updated'] = $progress['transactions_updated'] + 1;
                        $progress['fields_updated'] = $progress['fields_updated'] + count($changed);
                    }
                }

                $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);
            }
        );

        $progress['status'] = 'done';
        $progress['finished_at'] = $clock->now()->toIso8601String();
        $cache->put($cacheKey, $progress, self::PROGRESS_TTL_SECONDS);
    }

    private static function matchInputFromRow(
        stdClass $row,
        SensitiveColumnCodec $codec,
        Session $session,
        int $userId,
        bool $hasKek,
    ): RuleMatchInput {
        $counterpartyName = is_string($row->counterparty_name) ? $row->counterparty_name : null;
        $description = is_string($row->description) ? $row->description : null;

        // CR-04: decrypt-before-match — codec->decryptValue() is itself a
        // no-op pass-through when encryption isn't enabled, but skipping
        // the call entirely when $hasKek is false avoids a wasted
        // keyring-load attempt per row (the KEK-absence guard in handle()
        // already logged once for the whole run).
        if ($hasKek) {
            if ($counterpartyName !== null && $counterpartyName !== '') {
                $counterpartyName = $codec->decryptValue('transactions', 'counterparty_name', $counterpartyName, $userId, $session)['value'];
            }
            if ($description !== null && $description !== '') {
                $description = $codec->decryptValue('transactions', 'description', $description, $userId, $session)['value'];
            }
        }

        $settledAmountMinor = is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0;
        $postedAt = is_string($row->posted_at) ? CarbonImmutable::parse($row->posted_at) : CarbonImmutable::now();

        return new RuleMatchInput(
            counterpartyName: $counterpartyName,
            description: $description,
            settledAmountMinor: $settledAmountMinor,
            postedAt: $postedAt,
        );
    }
}
