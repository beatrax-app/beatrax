<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Exceptions\UnresolvedStagedAccountException;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;
use Modules\Migration\Models\MigrationRun;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use stdClass;

final class PromoteStagingToDomain
{
    use CoercesScalars;

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
        private readonly CounterpartyKey $counterpartyKey,
    ) {}

    /**
     * @param  list<string>  $skipBudgetAssignmentKeys  `{categoryExternalId}|{period_start}` composite keys
     *                                                  to leave byte-for-byte untouched (reconciliation conflicts).
     *                                                  Empty for a normal first-time confirm.
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

    /**
     * @return array{idMap: array<string, int>, created: int}
     */
    private function promoteCategories(int $runId, User $user, string $sourceProduct): array
    {
        $rows = $this->db->connection()->table('migration_staging_categories')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->orderBy('id')
            ->get();

        /** @var array<string, int> $idMap */
        $idMap = [];
        $created = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toString($row->source_external_id);
            $resolved = $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, 'category', $externalId));

            if ($resolved === null) {
                $parentExternalId = $row->parent_source_external_id;
                $parentId = null;
                if (is_string($parentExternalId) && $parentExternalId !== '') {
                    $parentId = $idMap[$parentExternalId]
                        ?? $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, 'category', $parentExternalId));
                }

                $name = self::toString($row->name);
                $kind = self::toString($row->kind);

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
                    new SourceMapKey($sourceProduct, 'category', $externalId),
                    'category',
                    $resolved,
                    ['name' => $name, 'kind' => $kind],
                );

                $created++;
            }

            $this->db->connection()->table('migration_staging_categories')
                ->where('id', self::toInt($row->id))
                ->where('user_id', $user->id)
                ->update(['resolution_status' => 'mapped', 'resolved_category_id' => $resolved]);

            $idMap[$externalId] = $resolved;
        }

        return ['idMap' => $idMap, 'created' => $created];
    }

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  list<string>  $skipKeys  `{categoryExternalId}|{period_start}` composite keys to leave
     *                                  untouched (reconciliation conflicts).
     */
    private function promoteBudgetAssignments(int $runId, User $user, string $sourceProduct, array $categoryIdMap, array $skipKeys = []): void
    {
        $rows = $this->db->connection()->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toString($row->source_category_external_id);
            $categoryId = $categoryIdMap[$categoryExternalId] ?? null;
            if ($categoryId === null) {
                continue;
            }

            $periodStart = CarbonImmutable::parse(self::toString($row->period_start));

            if (in_array($categoryExternalId.'|'.$periodStart->toDateString(), $skipKeys, true)) {
                continue;
            }

            $minor = self::toInt($row->budgeted_minor);

            $this->envelopeWriter->setAssigned($user, $categoryId, $periodStart, $minor);

            $assignmentId = $this->db->connection()->table('envelope_assignments')
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->where('period_start', $periodStart->toDateString())
                ->value('id');

            // setAssigned() deletes the row outright on a zero amount (absence == 0).
            if ($assignmentId !== null) {
                $externalId = $categoryExternalId.'|'.$periodStart->toDateString();

                $this->sourceMapWriter->record(
                    $user,
                    new SourceMapKey($sourceProduct, 'budget_assignment', $externalId),
                    'envelope_assignment',
                    self::toInt($assignmentId),
                    ['budgeted_minor' => (string) $minor],
                );
            }
        }
    }

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
            $externalId = self::toString($row->source_external_id);
            $resolved = $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, 'account', $externalId));

            if ($resolved === null) {
                $name = self::toString($row->name);
                $currency = self::toString($row->currency);

                /** @var Account $account */
                $account = Account::query()->create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'slug' => $this->uniqueSlug('accounts', $user, $name),
                    'kind' => self::toString($row->kind),
                    'iban' => self::syntheticIban($externalId),
                    'default_currency' => $currency,
                ]);

                $resolved = $account->id;

                $this->sourceMapWriter->record(
                    $user,
                    new SourceMapKey($sourceProduct, 'account', $externalId),
                    'account',
                    $resolved,
                    ['name' => $name, 'currency' => $currency],
                );

                $created++;
            }

            $this->db->connection()->table('migration_staging_accounts')
                ->where('id', self::toInt($row->id))
                ->where('user_id', $user->id)
                ->update(['resolution_status' => 'mapped', 'resolved_account_id' => $resolved]);

            $idMap[$externalId] = $resolved;
        }

        return ['idMap' => $idMap, 'created' => $created];
    }

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
                $prepared = $this->prepareCanonicalRows($runId, $user, $sourceProduct, $rows, $categoryIdMap, $accountIdMap, $payeeNameMap);
                $skipped += $prepared['skipped'];

                if ($prepared['canonicals'] === []) {
                    return;
                }

                // The RecordResult is discarded on purpose: the per-row fingerprint
                // lookup below names the exact staged row a collision dropped.
                ($this->recordTransactions)($prepared['canonicals'], $user);

                $counts = $this->persistPromotedRows(
                    $runId,
                    $user,
                    $sourceProduct,
                    new PreparedTransactionBatch($prepared['rows'], $prepared['canonicals']),
                    $categoryIdMap,
                    $payeeNameMap,
                    $resolvedPayees,
                );
                $inserted += $counts['inserted'];
                $splitsCreated += $counts['splits'];
            });

        return [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'splits' => $splitsCreated,
            'counterparties' => count($resolvedPayees),
        ];
    }

    /**
     * @param  iterable<int, stdClass>  $rows
     * @param  array<string, int>  $categoryIdMap
     * @param  array<string, int>  $accountIdMap
     * @param  array<string, string>  $payeeNameMap
     * @return array{rows: list<stdClass>, canonicals: list<CanonicalTransaction>, skipped: int}
     */
    private function prepareCanonicalRows(
        int $runId,
        User $user,
        string $sourceProduct,
        iterable $rows,
        array $categoryIdMap,
        array $accountIdMap,
        array $payeeNameMap,
    ): array {
        /** @var list<stdClass> $newRows */
        $newRows = [];
        /** @var list<CanonicalTransaction> $newCanonicals */
        $newCanonicals = [];
        $skipped = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toString($row->source_external_id);
            $alreadyMapped = $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, 'transaction', $externalId));

            if ($alreadyMapped !== null) {
                $skipped++;

                continue;
            }

            $canonical = $this->buildCanonicalTransaction($runId, $user, $sourceProduct, $row, $categoryIdMap, $accountIdMap, $payeeNameMap);

            $canonical = $this->resolvesCounterparties->run($canonical, $user);

            $newRows[] = $row;
            $newCanonicals[] = $canonical;
        }

        return ['rows' => $newRows, 'canonicals' => $newCanonicals, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  array<string, string>  $payeeNameMap
     * @param  array<string, true>  $resolvedPayees
     * @return array{inserted: int, splits: int}
     */
    private function persistPromotedRows(
        int $runId,
        User $user,
        string $sourceProduct,
        PreparedTransactionBatch $batch,
        array $categoryIdMap,
        array $payeeNameMap,
        array &$resolvedPayees,
    ): array {
        $newRows = $batch->rows;
        $newCanonicals = $batch->canonicals;

        $fingerprintsByIndex = [];
        foreach ($newCanonicals as $idx => $canonical) {
            $fingerprintsByIndex[$idx] = $this->fingerprints->compose($canonical);
        }

        /** @var Collection<string, int> $idsByFingerprint */
        $idsByFingerprint = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('fingerprint', array_values($fingerprintsByIndex))
            ->pluck('id', 'fingerprint');

        $inserted = 0;
        $splitsCreated = 0;

        foreach ($newRows as $idx => $row) {
            $description = $row->description !== null ? self::toString($row->description) : null;
            $fingerprint = $fingerprintsByIndex[$idx];
            $transactionId = $idsByFingerprint->has($fingerprint)
                ? self::toInt($idsByFingerprint->get($fingerprint))
                : null;

            if ($transactionId === null) {
                $this->db->connection()->table('migration_staging_unmapped_items')->insert([
                    'user_id' => $user->id,
                    'migration_run_id' => $runId,
                    'item_type' => 'extra',
                    'source_external_id' => self::toString($row->source_external_id),
                    'display_label' => 'Transaction: '.($description ?? '(no description)'),
                    'reason' => 'This transaction collided with another already-recorded transaction (identical fingerprint) and was not imported.',
                ]);

                continue;
            }

            // CanonicalTransaction::toAttributes() hard-stamps 'cleared' for any
            // non-'manual' sourceFormat, so the staged status is re-applied here.
            $this->db->connection()->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update(['status' => self::toString($row->cleared_status)]);

            $this->sourceMapWriter->record(
                $user,
                new SourceMapKey($sourceProduct, 'transaction', self::toString($row->source_external_id)),
                'transaction',
                $transactionId,
                [
                    'description' => $description ?? '',
                    'amount_minor' => (string) self::toInt($row->amount_minor),
                ],
            );

            $inserted++;

            if ((bool) $row->is_split_parent && $this->createSplitLegs($runId, $user, $row, $transactionId, $categoryIdMap)) {
                $splitsCreated++;
            }

            $this->mapPayeeToCounterparty($runId, $user, $sourceProduct, $row, $newCanonicals[$idx], $payeeNameMap, $resolvedPayees);
        }

        return ['inserted' => $inserted, 'splits' => $splitsCreated];
    }

    /**
     * @param  array<string, string>  $payeeNameMap
     * @param  array<string, true>  $resolvedPayees
     */
    private function mapPayeeToCounterparty(
        int $runId,
        User $user,
        string $sourceProduct,
        stdClass $row,
        CanonicalTransaction $canonical,
        array $payeeNameMap,
        array &$resolvedPayees,
    ): void {
        $payeeExternalId = $row->payee_source_external_id;
        if (
            ! is_string($payeeExternalId)
            || $payeeExternalId === ''
            || $canonical->counterpartyId === null
            || isset($resolvedPayees[$payeeExternalId])
        ) {
            return;
        }

        $resolvedPayees[$payeeExternalId] = true;

        $this->sourceMapWriter->record(
            $user,
            new SourceMapKey($sourceProduct, 'payee', $payeeExternalId),
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

    /**
     * @param  array<string, int>  $categoryIdMap
     */
    private function createSplitLegs(int $runId, User $user, stdClass $parentRow, int $transactionId, array $categoryIdMap): bool
    {
        $legRows = $this->db->connection()->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->where('parent_source_external_id', self::toString($parentRow->source_external_id))
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
                'note' => $legRow->description !== null ? self::toString($legRow->description) : null,
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
        $accountExternalId = self::toString($row->account_source_external_id);
        $accountId = $accountIdMap[$accountExternalId] ?? null;
        if ($accountId === null) {
            throw new UnresolvedStagedAccountException($accountExternalId);
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
        $counterpartyNormalized = $this->counterpartyKey->forName($counterpartyName, $user->id);

        $categoryExternalId = $row->category_source_external_id;
        $categoryId = is_string($categoryExternalId) ? ($categoryIdMap[$categoryExternalId] ?? null) : null;

        $postedAt = CarbonImmutable::parse(self::toString($row->posted_at));

        // Every postedAt is midnight (no source carries a time-of-day); the
        // per-row offset keeps two same-day rows off one fingerprint.
        $bookedAt = $postedAt->addSeconds(self::toInt($row->id) % Duration::Day->seconds());

        return new CanonicalTransaction(
            userId: $user->id,
            accountId: $accountId,
            type: $type,
            postedAt: $postedAt,
            bookedAt: $bookedAt,
            valueDate: $postedAt,
            amountMinor: $amountMinor,
            currency: self::toString($row->currency),
            settledAmountMinor: self::toInt($row->settled_amount_minor),
            settledCurrency: self::toString($row->settled_currency),
            fxRateUsed: null,
            counterpartyName: $counterpartyName,
            counterpartyIban: $counterpartyIban,
            counterpartyNormalized: $counterpartyNormalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $row->description !== null ? self::toString($row->description) : null,
            categoryId: $categoryId,
            sourceFormat: 'migration_'.$sourceProduct,
            importRunId: $this->migrationImportRunId($user, $sourceProduct),
            sourceRowIndex: 0,
            sourceRef: 'migration:'.$sourceProduct.':'.self::toString($row->source_external_id),
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

        $partnerAccountExternalId = self::toString($partnerRow->account_source_external_id);
        $partnerAccountId = $accountIdMap[$partnerAccountExternalId] ?? null;
        if ($partnerAccountId === null) {
            return null;
        }

        $iban = $this->db->connection()->table('accounts')
            ->where('id', $partnerAccountId)
            ->where('user_id', $user->id)
            ->value('iban');

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
                $map[$row->source_external_id] = self::toString($row->normalized_name);
            }
        }

        return $map;
    }

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
            'status' => MigrationRunStatus::Confirmed->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    private function promoteGoals(int $runId, User $user, string $sourceProduct): int
    {
        $rows = $this->db->connection()->table('migration_staging_goals')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        $created = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toString($row->category_source_external_id);
            $resolved = $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, 'goal', $categoryExternalId));
            if ($resolved !== null) {
                continue;
            }

            $targetDate = $row->target_date;
            $name = self::toString($row->name);

            if (! is_string($targetDate) || $targetDate === '') {
                $this->db->connection()->table('migration_staging_unmapped_items')->insert([
                    'user_id' => $user->id,
                    'migration_run_id' => $runId,
                    'item_type' => 'extra',
                    'source_external_id' => $categoryExternalId,
                    'display_label' => 'Goal: '.$name,
                    'reason' => 'This goal has no target date; Beatrax requires one to create a savings goal.',
                ]);

                continue;
            }

            $goal = $this->goalWriter->save(
                $user,
                $name,
                self::minorToDecimalString(self::toInt($row->target_minor)),
                $targetDate,
            );

            $this->sourceMapWriter->record(
                $user,
                new SourceMapKey($sourceProduct, 'goal', $categoryExternalId),
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

    private static function syntheticIban(string $sourceExternalId): string
    {
        return 'MIG'.strtoupper(hash('crc32b', $sourceExternalId));
    }

    private static function minorToDecimalString(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $whole = intdiv(abs($minor), 100);
        $frac = abs($minor) % 100;

        return sprintf('%s%d.%02d', $sign, $whole, $frac);
    }
}
