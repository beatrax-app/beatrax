<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Models\MigrationRun;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use RuntimeException;
use stdClass;

/**
 * Promotes one migration run's `migration_staging_*` rows into the user's
 * real domain tables through the six existing public writers, in the
 * mandatory fixed order (RESEARCH.md architecture diagram):
 *
 *   categories -> budget grid (EnvelopeWriter::setAssigned) -> accounts ->
 *   (ResolvesCounterparties::run THEN RecordTransactions) -> splits
 *   (SaveTransactionSplit) -> transfer sweep (pairOrphansForUser) -> goals
 *   (GoalWriter, Actual only).
 *
 * Bounded-not-atomic (mirrors `Modules\Import\Public\Actions\ConfirmImport`):
 * every step below runs its own per-entity writes OUTSIDE one giant
 * transaction — a multi-year promote must never be a single unbounded DB
 * transaction. Transactions are additionally chunked (`chunkById(500, ...)`,
 * never collapsed into one whole in-memory result set) mirroring
 * `RecordTransactions::persistChunk()`'s `CHUNK_SIZE = 500` convention.
 *
 * Idempotency gate (Req 9, D-08/D-09): EVERY step first asks
 * `SourceMapWriter::resolve()` whether this source entity was already
 * promoted by an earlier confirm of the identical export. A hit means the
 * entity is reused (its beatrax id) and NO further writes happen for it —
 * this is what makes a re-run of the identical export a true no-op across
 * categories/accounts/transactions, not merely "safe to call again" via each
 * writer's own internal upsert semantics. Budget-grid rows are the one
 * deliberate exception: `EnvelopeWriter::setAssigned()` is already idempotent
 * by value (re-setting the same amount is a no-op with no new row/event), so
 * no separate resolve-gate is needed there.
 *
 * Transaction-parent granularity source-map (documented simplification):
 * `migration_source_map` records ONE row per promoted transaction (not one
 * per split leg) — `SaveTransactionSplit::save()` has a `void` return (no leg
 * ids to map), and the parent-level resolve-gate above already fully
 * protects split-leg re-run idempotency (an already-mapped parent is skipped
 * before its legs are ever touched again). A `transfer_pair` source_map
 * entity type is likewise not separately recorded: each transfer LEG already
 * gets its own `'transaction'` source_map row, which is enough for Plan 07 to
 * relocate either leg individually; `pair_transaction_id` on the transaction
 * rows themselves is what Req 5's linkage actually rests on.
 */
final class PromoteStagingToDomain
{
    /** Mirrors `RecordTransactions::CHUNK_SIZE` — never one unbounded pass. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SourceMapWriter $sourceMapWriter,
        private readonly EnvelopeWriter $envelopeWriter,
        private readonly RecordsTransactions $recordTransactions,
        private readonly ResolvesCounterparties $resolvesCounterparties,
        private readonly SavesTransactionSplit $splitSaver,
        private readonly PairsTransferLegs $transferPairer,
        private readonly GoalWriter $goalWriter,
        private readonly FingerprintComposer $fingerprints,
    ) {}

    /**
     * @param  list<string>  $skipBudgetAssignmentKeys  `{categoryExternalId}|{period_start}` composite keys
     *                                                  to leave byte-for-byte untouched (Plan 07 reconciliation
     *                                                  conflicts — D-12/D-13). Empty for a normal first-time
     *                                                  confirm; `ConfirmMigration`'s call site never supplies
     *                                                  this, preserving its exact prior behavior.
     */
    public function promote(int $runId, User $user, array $skipBudgetAssignmentKeys = []): PromoteResult
    {
        /** @var MigrationRun $run */
        $run = MigrationRun::query()->where('id', $runId)->where('user_id', $user->id)->firstOrFail();
        $sourceProduct = $run->source_product;

        $categories = $this->promoteCategories($runId, $user, $sourceProduct);
        $this->promoteBudgetAssignments($runId, $user, $sourceProduct, $categories['idMap'], $skipBudgetAssignmentKeys);

        $accounts = $this->promoteAccounts($runId, $user, $sourceProduct);

        $transactions = $this->promoteTransactions(
            $runId,
            $user,
            $sourceProduct,
            $categories['idMap'],
            $accounts['idMap'],
        );

        $transfersPaired = $this->transferPairer->pairOrphansForUser($user);

        $goalsCreated = $this->promoteGoals($runId, $user, $sourceProduct);

        return new PromoteResult(
            categoriesCreated: $categories['created'],
            accountsCreated: $accounts['created'],
            transactionsInserted: $transactions['inserted'],
            transactionsSkipped: $transactions['skipped'],
            splitsCreated: $transactions['splits'],
            transfersPaired: $transfersPaired,
            counterpartiesResolved: $transactions['counterparties'],
            goalsCreated: $goalsCreated,
        );
    }

    // -------------------------------------------------------------------
    // Step 1: Categories
    // -------------------------------------------------------------------

    /**
     * @return array{idMap: array<string, int>, created: int}
     */
    private function promoteCategories(int $runId, User $user, string $sourceProduct): array
    {
        $rows = $this->db->connection()->table('migration_staging_categories')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        /** @var array<string, int> $idMap */
        $idMap = [];
        $created = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toStr($row->source_external_id);
            $resolved = $this->sourceMapWriter->resolve($user, $sourceProduct, 'category', $externalId);

            if ($resolved === null) {
                $parentExternalId = $row->parent_source_external_id;
                $parentId = null;
                if (is_string($parentExternalId) && $parentExternalId !== '') {
                    $parentId = $idMap[$parentExternalId]
                        ?? $this->sourceMapWriter->resolve($user, $sourceProduct, 'category', $parentExternalId);
                }

                $name = self::toStr($row->name);
                $kind = self::toStr($row->kind);

                /** @var Category $category */
                $category = Category::query()->create([
                    'user_id' => $user->id,
                    'parent_id' => $parentId,
                    'name' => $name,
                    'slug' => $this->uniqueSlug('categories', $user, $name),
                    'kind' => $kind,
                    'display_order' => 100,
                ]);

                $resolved = $category->id;

                $this->sourceMapWriter->record(
                    $user,
                    $sourceProduct,
                    'category',
                    $externalId,
                    null,
                    'category',
                    $resolved,
                    ['name' => $name, 'kind' => $kind],
                );

                $created++;
            }

            $this->db->connection()->table('migration_staging_categories')
                ->where('id', self::toInt($row->id))
                ->update(['resolution_status' => 'mapped', 'resolved_category_id' => $resolved]);

            $idMap[$externalId] = $resolved;
        }

        return ['idMap' => $idMap, 'created' => $created];
    }

    // -------------------------------------------------------------------
    // Step 2: Budget grid
    // -------------------------------------------------------------------

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  list<string>  $skipKeys  `{categoryExternalId}|{period_start}` composite keys to leave
     *                                  untouched (Plan 07 reconciliation conflicts — D-12/D-13).
     */
    private function promoteBudgetAssignments(int $runId, User $user, string $sourceProduct, array $categoryIdMap, array $skipKeys = []): void
    {
        $rows = $this->db->connection()->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toStr($row->source_category_external_id);
            $categoryId = $categoryIdMap[$categoryExternalId] ?? null;
            if ($categoryId === null) {
                continue;
            }

            $periodStart = CarbonImmutable::parse(self::toStr($row->period_start));

            if (in_array($categoryExternalId.'|'.$periodStart->toDateString(), $skipKeys, true)) {
                // Reconciliation conflict (Req 10/D-12): the local value ALSO
                // diverged from the baseline, so this row's source change is
                // applied to NOTHING — the beatrax value stays byte-for-byte
                // untouched until the user resolves it (D-14 keep-local
                // default is enforced by ThreeWayMergeResolver/CheckForUpdates,
                // not here).
                continue;
            }

            $minor = self::toInt($row->budgeted_minor);

            // setAssigned() is idempotent by value (Req 3/D-06): re-setting
            // the same amount on a re-run is a no-op — no separate
            // resolve-gate needed here, unlike categories/accounts/txns.
            $this->envelopeWriter->setAssigned($user, $categoryId, $periodStart, $minor);

            $assignmentId = $this->db->connection()->table('envelope_assignments')
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->where('period_start', $periodStart->toDateString())
                ->value('id');

            // A zero-minor assignment DELETES the row (D-06 absence == 0) —
            // nothing persisted to map.
            if ($assignmentId !== null) {
                $externalId = $categoryExternalId.'|'.$periodStart->toDateString();

                $this->sourceMapWriter->record(
                    $user,
                    $sourceProduct,
                    'budget_assignment',
                    $externalId,
                    null,
                    'envelope_assignment',
                    self::toInt($assignmentId),
                    ['budgeted_minor' => (string) $minor],
                );
            }
        }
    }

    // -------------------------------------------------------------------
    // Step 3: Accounts
    // -------------------------------------------------------------------

    /**
     * @return array{idMap: array<string, int>, created: int}
     */
    private function promoteAccounts(int $runId, User $user, string $sourceProduct): array
    {
        $rows = $this->db->connection()->table('migration_staging_accounts')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        /** @var array<string, int> $idMap */
        $idMap = [];
        $created = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toStr($row->source_external_id);
            $resolved = $this->sourceMapWriter->resolve($user, $sourceProduct, 'account', $externalId);

            if ($resolved === null) {
                $name = self::toStr($row->name);
                $currency = self::toStr($row->currency);

                /** @var Account $account */
                $account = Account::query()->create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'slug' => $this->uniqueSlug('accounts', $user, $name),
                    'kind' => self::toStr($row->kind),
                    // No real bank IBAN exists for a migrated account —
                    // synthesize a deterministic, per-user-unique pseudo-IBAN
                    // literal (mirrors the codebase's existing 'PAYPAL' /
                    // 'ICS-CARD' / 'CASH...' synthetic-IBAN convention) so
                    // Transfers\PairsTransferLegs can still match this
                    // account's transfer legs by IBAN like any other.
                    'iban' => self::syntheticIban($externalId),
                    'default_currency' => $currency,
                ]);

                $resolved = $account->id;

                $this->sourceMapWriter->record(
                    $user,
                    $sourceProduct,
                    'account',
                    $externalId,
                    null,
                    'account',
                    $resolved,
                    ['name' => $name, 'currency' => $currency],
                );

                $created++;
            }

            $this->db->connection()->table('migration_staging_accounts')
                ->where('id', self::toInt($row->id))
                ->update(['resolution_status' => 'mapped', 'resolved_account_id' => $resolved]);

            $idMap[$externalId] = $resolved;
        }

        return ['idMap' => $idMap, 'created' => $created];
    }

    // -------------------------------------------------------------------
    // Step 4: Transactions (+ splits, + payee->counterparty mapping)
    // -------------------------------------------------------------------

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  array<string, int>  $accountIdMap
     * @return array{inserted: int, skipped: int, splits: int, counterparties: int}
     */
    private function promoteTransactions(
        int $runId,
        User $user,
        string $sourceProduct,
        array $categoryIdMap,
        array $accountIdMap,
    ): array {
        $payeeNameMap = $this->loadPayeeNameMap($runId, $user);

        $inserted = 0;
        $skipped = 0;
        $splitsCreated = 0;
        /** @var array<string, true> $resolvedPayees */
        $resolvedPayees = [];

        $this->db->connection()->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->whereNull('parent_source_external_id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (
                $runId,
                $user,
                $sourceProduct,
                $categoryIdMap,
                $accountIdMap,
                $payeeNameMap,
                &$inserted,
                &$skipped,
                &$splitsCreated,
                &$resolvedPayees,
            ): void {
                /** @var list<stdClass> $newRows */
                $newRows = [];
                /** @var list<CanonicalTransaction> $newCanonicals */
                $newCanonicals = [];

                /** @var stdClass $row */
                foreach ($rows as $row) {
                    $externalId = self::toStr($row->source_external_id);
                    $alreadyMapped = $this->sourceMapWriter->resolve($user, $sourceProduct, 'transaction', $externalId);

                    if ($alreadyMapped !== null) {
                        $skipped++;

                        continue;
                    }

                    $canonical = $this->buildCanonicalTransaction(
                        $runId,
                        $user,
                        $sourceProduct,
                        $row,
                        $categoryIdMap,
                        $accountIdMap,
                        $payeeNameMap,
                    );

                    // MUST run before RecordTransactions (Pitfall 3) — the
                    // stamped counterpartyId is what RecordTransactions
                    // persists; it never resolves counterparties itself.
                    $canonical = $this->resolvesCounterparties->run($canonical, $user);

                    $newRows[] = $row;
                    $newCanonicals[] = $canonical;
                }

                if ($newCanonicals === []) {
                    return;
                }

                ($this->recordTransactions)($newCanonicals, $user);

                $fingerprintsByIndex = [];
                foreach ($newCanonicals as $idx => $canonical) {
                    $fingerprintsByIndex[$idx] = $this->fingerprints->compose($canonical);
                }

                /** @var Collection<string, int> $idsByFingerprint */
                $idsByFingerprint = $this->db->connection()->table('transactions')
                    ->where('user_id', $user->id)
                    ->whereIn('fingerprint', array_values($fingerprintsByIndex))
                    ->pluck('id', 'fingerprint');

                foreach ($newRows as $idx => $row) {
                    $canonical = $newCanonicals[$idx];
                    $fingerprint = $fingerprintsByIndex[$idx];
                    $transactionId = $idsByFingerprint->has($fingerprint)
                        ? self::toInt($idsByFingerprint->get($fingerprint))
                        : null;

                    if ($transactionId === null) {
                        // Defensive: RecordTransactions silently dropped this
                        // row as a fingerprint-duplicate of an unrelated row
                        // (a genuine cross-source coincidence) — nothing to
                        // link or map.
                        continue;
                    }

                    // cleared_status -> status (Req 4):
                    // CanonicalTransaction::toAttributes() always stamps
                    // 'cleared' for a non-'manual' sourceFormat, so the real
                    // staged status is applied explicitly right after insert.
                    $this->db->connection()->table('transactions')
                        ->where('id', $transactionId)
                        ->update(['status' => self::toStr($row->cleared_status)]);

                    $this->sourceMapWriter->record(
                        $user,
                        $sourceProduct,
                        'transaction',
                        self::toStr($row->source_external_id),
                        null,
                        'transaction',
                        $transactionId,
                        [
                            'description' => $row->description !== null ? self::toStr($row->description) : '',
                            'amount_minor' => (string) self::toInt($row->amount_minor),
                        ],
                    );

                    $inserted++;

                    if ((bool) $row->is_split_parent) {
                        if ($this->createSplitLegs($runId, $user, $row, $transactionId, $categoryIdMap)) {
                            $splitsCreated++;
                        }
                    }

                    $payeeExternalId = $row->payee_source_external_id;
                    if (
                        is_string($payeeExternalId)
                        && $payeeExternalId !== ''
                        && $canonical->counterpartyId !== null
                        && ! isset($resolvedPayees[$payeeExternalId])
                    ) {
                        $resolvedPayees[$payeeExternalId] = true;

                        $this->sourceMapWriter->record(
                            $user,
                            $sourceProduct,
                            'payee',
                            $payeeExternalId,
                            null,
                            'counterparty',
                            $canonical->counterpartyId,
                            ['name' => $payeeNameMap[$payeeExternalId] ?? ''],
                        );

                        $this->db->connection()->table('migration_staging_payees')
                            ->where('user_id', $user->id)
                            ->where('migration_run_id', $runId)
                            ->where('source_external_id', $payeeExternalId)
                            ->update([
                                'resolution_status' => 'mapped',
                                'resolved_counterparty_id' => $canonical->counterpartyId,
                            ]);
                    }
                }
            });

        return [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'splits' => $splitsCreated,
            'counterparties' => count($resolvedPayees),
        ];
    }

    /**
     * @param  array<string, int>  $categoryIdMap
     */
    private function createSplitLegs(int $runId, User $user, stdClass $parentRow, int $transactionId, array $categoryIdMap): bool
    {
        $legRows = $this->db->connection()->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->where('parent_source_external_id', self::toStr($parentRow->source_external_id))
            ->orderBy('id')
            ->get();

        /** @var list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}> $legs */
        $legs = [];

        /** @var stdClass $legRow */
        foreach ($legRows as $legRow) {
            $legCategoryExternalId = $legRow->category_source_external_id;
            $legCategoryId = is_string($legCategoryExternalId) ? ($categoryIdMap[$legCategoryExternalId] ?? null) : null;

            if ($legCategoryId === null) {
                continue;
            }

            $legs[] = [
                'id' => null,
                'category_id' => $legCategoryId,
                'settled_amount_minor' => self::toInt($legRow->settled_amount_minor),
                'note' => $legRow->description !== null ? self::toStr($legRow->description) : null,
            ];
        }

        if (count($legs) < 2) {
            return false;
        }

        $this->splitSaver->save($user, $transactionId, $legs);

        return true;
    }

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  array<string, int>  $accountIdMap
     * @param  array<string, string>  $payeeNameMap
     */
    private function buildCanonicalTransaction(
        int $runId,
        User $user,
        string $sourceProduct,
        stdClass $row,
        array $categoryIdMap,
        array $accountIdMap,
        array $payeeNameMap,
    ): CanonicalTransaction {
        $accountExternalId = self::toStr($row->account_source_external_id);
        $accountId = $accountIdMap[$accountExternalId] ?? null;
        if ($accountId === null) {
            throw new RuntimeException("Migration promote: no resolved account for staged transaction account '{$accountExternalId}'.");
        }

        $amountMinor = self::toInt($row->amount_minor);
        $transferCounterpartExternalId = $row->transfer_counterpart_source_external_id;
        $isTransfer = is_string($transferCounterpartExternalId) && $transferCounterpartExternalId !== '';

        $counterpartyIban = $isTransfer
            ? $this->resolveTransferCounterpartyIban($runId, $user, $transferCounterpartExternalId, $accountIdMap)
            : null;

        $type = match (true) {
            $isTransfer => $amountMinor < 0 ? 'transfer_out' : 'transfer_in',
            $amountMinor > 0 => 'income',
            $amountMinor < 0 => 'expense',
            default => 'adjustment',
        };

        $payeeExternalId = $row->payee_source_external_id;
        $counterpartyName = is_string($payeeExternalId) ? ($payeeNameMap[$payeeExternalId] ?? null) : null;
        $counterpartyNormalized = $this->normalizeCounterpartyName($counterpartyName);

        $categoryExternalId = $row->category_source_external_id;
        $categoryId = is_string($categoryExternalId) ? ($categoryIdMap[$categoryExternalId] ?? null) : null;

        $postedAt = CarbonImmutable::parse(self::toStr($row->posted_at));

        return new CanonicalTransaction(
            userId: $user->id,
            accountId: $accountId,
            type: $type,
            postedAt: $postedAt,
            bookedAt: $postedAt,
            valueDate: $postedAt,
            amountMinor: $amountMinor,
            currency: self::toStr($row->currency),
            settledAmountMinor: self::toInt($row->settled_amount_minor),
            settledCurrency: self::toStr($row->settled_currency),
            fxRateUsed: null,
            counterpartyName: $counterpartyName,
            counterpartyIban: $counterpartyIban,
            counterpartyNormalized: $counterpartyNormalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $row->description !== null ? self::toStr($row->description) : null,
            categoryId: $categoryId,
            sourceFormat: 'migration_'.$sourceProduct,
            importRunId: $this->migrationImportRunId($user, $sourceProduct),
            sourceRowIndex: 0,
            sourceRef: 'migration:'.$sourceProduct.':'.self::toStr($row->source_external_id),
        );
    }

    /**
     * @param  array<string, int>  $accountIdMap
     */
    private function resolveTransferCounterpartyIban(int $runId, User $user, string $counterpartExternalId, array $accountIdMap): ?string
    {
        $partnerRow = $this->db->connection()->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->where('source_external_id', $counterpartExternalId)
            ->first(['account_source_external_id']);

        if ($partnerRow === null) {
            return null;
        }

        $partnerAccountExternalId = self::toStr($partnerRow->account_source_external_id);
        $partnerAccountId = $accountIdMap[$partnerAccountExternalId] ?? null;
        if ($partnerAccountId === null) {
            return null;
        }

        $iban = $this->db->connection()->table('accounts')->where('id', $partnerAccountId)->value('iban');

        return is_string($iban) ? $iban : null;
    }

    /**
     * @return array<string, string>
     */
    private function loadPayeeNameMap(int $runId, User $user): array
    {
        $rows = $this->db->connection()->table('migration_staging_payees')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get(['source_external_id', 'normalized_name']);

        /** @var array<string, string> $map */
        $map = [];

        /** @var stdClass $row */
        foreach ($rows as $row) {
            if (is_string($row->source_external_id) && $row->source_external_id !== '') {
                $map[$row->source_external_id] = self::toStr($row->normalized_name);
            }
        }

        return $map;
    }

    private function normalizeCounterpartyName(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return NormalizeStage::NO_COUNTERPARTY;
        }

        $normalized = $this->fingerprints->normalize($name);

        return $normalized === '' ? NormalizeStage::NO_COUNTERPARTY : $normalized;
    }

    /**
     * Finds-or-creates a single per-(user, sourceProduct) synthetic
     * `import_runs` row so migrated transactions can satisfy
     * `transactions.import_run_id`'s required FK — mirrors
     * `Modules\CashBook\Internal\Actions\RecordManualTransaction::manualRunId()`'s
     * identical synthetic-fixture precedent (a manual cash entry needs the
     * same FK satisfied and is likewise not a real file import).
     */
    private function migrationImportRunId(User $user, string $sourceProduct): int
    {
        $sourceFormat = 'migration_'.$sourceProduct;
        $connection = $this->db->connection();

        $existingId = $connection->table('import_runs')
            ->where('user_id', $user->id)
            ->where('source_format', $sourceFormat)
            ->value('id');

        if (is_numeric($existingId)) {
            return (int) $existingId;
        }

        $now = $this->clock->now();

        return self::toInt($connection->table('import_runs')->insertGetId([
            'user_id' => $user->id,
            'source_format' => $sourceFormat,
            'raw_file_path' => 'migration',
            'sha256' => hash('sha256', 'migration:'.$sourceProduct),
            'uploaded_at' => $now,
            'status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    // -------------------------------------------------------------------
    // Step 7: Goals (Actual only)
    // -------------------------------------------------------------------

    private function promoteGoals(int $runId, User $user, string $sourceProduct): int
    {
        $rows = $this->db->connection()->table('migration_staging_goals')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        $created = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toStr($row->category_source_external_id);
            $resolved = $this->sourceMapWriter->resolve($user, $sourceProduct, 'goal', $categoryExternalId);
            if ($resolved !== null) {
                continue;
            }

            $targetDate = $row->target_date;
            $name = self::toStr($row->name);

            if (! is_string($targetDate) || $targetDate === '') {
                // beatrax's Goal model requires a non-null target_date; a
                // dateless flat goal_def cannot be represented without
                // inventing one, so it is surfaced as unmapped instead of a
                // lossy approximation (mirrors the non-flat goal_def "extra"
                // convention the parser itself already applies).
                $this->db->connection()->table('migration_staging_unmapped_items')->insert([
                    'user_id' => $user->id,
                    'migration_run_id' => $runId,
                    'item_type' => 'extra',
                    'source_external_id' => $categoryExternalId,
                    'display_label' => 'Goal: '.$name,
                    'reason' => 'This goal has no target date; beatrax requires one to create a savings goal.',
                ]);

                continue;
            }

            $goal = $this->goalWriter->save(
                $user,
                $name,
                self::minorToDecimalString(self::toInt($row->target_minor)),
                $targetDate,
                null,
            );

            $this->sourceMapWriter->record(
                $user,
                $sourceProduct,
                'goal',
                $categoryExternalId,
                null,
                'goal',
                $goal->id,
                [
                    'target_minor' => (string) self::toInt($row->target_minor),
                    'target_date' => $targetDate,
                ],
            );

            $created++;
        }

        return $created;
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function uniqueSlug(string $table, User $user, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'item';
        }

        $connection = $this->db->connection();
        $slug = $base;
        $suffix = 2;

        while ($connection->table($table)->where('user_id', $user->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Deterministic, per-user-unique pseudo-IBAN literal for a migrated
     * account (no real bank IBAN exists for this data). Short enough to fit
     * `accounts.iban`'s 34-char column comfortably.
     */
    private static function syntheticIban(string $sourceExternalId): string
    {
        return 'MIG'.strtoupper(hash('crc32b', $sourceExternalId));
    }

    /**
     * Formats a positive integer-minor amount as the plain decimal string
     * `GoalWriter::parseAmount()` accepts (e.g. `200.00`). Goal amounts are
     * always positive per `ActualGoalDefInterpreter`'s own `$amount > 0`
     * guard; the sign is still handled defensively.
     */
    private static function minorToDecimalString(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $whole = intdiv(abs($minor), 100);
        $frac = abs($minor) % 100;

        return sprintf('%s%d.%02d', $sign, $whole, $frac);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
