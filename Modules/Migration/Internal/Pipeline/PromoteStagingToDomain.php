<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\IdReadBack;
use Modules\Core\Public\Support\RowChunk;
use Modules\Core\Public\Support\UniqueSlug;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Ledger\Public\Services\TransactionStatusWriter;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Exceptions\UnresolvedStagedAccountException;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Support\MigrationSourceFormat;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use stdClass;

final class PromoteStagingToDomain
{
    use CoercesScalars;

    private const int CHUNK_SIZE = RowChunk::DEFAULT_SIZE;

    private const string CATEGORY_SLUG_FALLBACK = 'item';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SourceMapWriter $sourceMapWriter,
        private readonly UnmappedItemReporter $unmappedItems,
        private readonly PromoteBudgetAssignments $budgetAssignments,
        private readonly RecordsTransactions $recordTransactions,
        private readonly ResolvesCounterparties $resolvesCounterparties,
        private readonly SavesTransactionSplit $splitSaver,
        private readonly PairsTransferLegs $transferPairer,
        private readonly GoalWriter $goalWriter,
        private readonly FingerprintComposer $fingerprints,
        private readonly CounterpartyKey $counterpartyKey,
        private readonly AccountSlugResolver $accountSlugs,
        private readonly TransactionStatusQuery $statusQuery,
        private readonly TransactionStatusWriter $statusWriter,
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
        $this->importRunId = null;

        $categories = $this->promoteCategories($runId, $user, $sourceProduct);
        $budgetMonthsWritten = $this->budgetAssignments->promote($runId, $user, $sourceProduct, $categories['idMap'], $skipBudgetAssignmentKeys);

        $accounts = $this->promoteAccounts($runId, $user, $sourceProduct);

        // RecordTransactions dispatches TransactionImported per row, and the
        // Transfers listener pairs most legs there and then, so the sweep's own
        // return counts only the leftovers. Both halves land in this delta.
        $pairedRowsBefore = $this->countPairedRows($user);

        $transactions = $this->promoteTransactions(
            $runId,
            $user,
            $sourceProduct,
            $categories['idMap'],
            $accounts['idMap'],
        );

        $this->transferPairer->pairOrphansForUser($user);

        $transfersPaired = intdiv(max(0, $this->countPairedRows($user) - $pairedRowsBefore), 2);

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
            budgetMonthsWritten: $budgetMonthsWritten,
        );
    }

    // Every new pair sets pair_transaction_id on exactly two rows, so the
    // half-difference across a promotion is the number of pairs it formed.
    private function countPairedRows(User $user): int
    {
        return $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('pair_transaction_id')
            ->count();
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

                $existing = $this->categoryAlreadyAtThisPath($user, $parentId, $name, $kind);

                if ($existing === null) {
                    /** @var Category $category */
                    $category = Category::query()->create([
                        'user_id' => $user->id,
                        'parent_id' => $parentId,
                        'name' => $name,
                        'slug' => $this->uniqueCategorySlug($user, $name),
                        'kind' => $kind,
                        'display_order' => 100,
                    ]);

                    $existing = $category->id;
                    $created++;
                }

                $resolved = $existing;

                $this->sourceMapWriter->record(
                    $user,
                    new SourceMapKey($sourceProduct, 'category', $externalId),
                    'category',
                    $resolved,
                    ['name' => $name, 'kind' => $kind],
                );
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
                    'slug' => $this->accountSlugs->resolveUnique($user->id, $name),
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
        // Carried across chunks the way $resolvedPayees is, and counted over
        // every staged row including the ones already mapped: the same row in a
        // later export only lands on the same ordinal if the rows ahead of it
        // are counted whether or not this run promotes them.
        /** @var array<string, int> $sameFingerprintOrdinals */
        $sameFingerprintOrdinals = [];

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
                &$sameFingerprintOrdinals,
            ): void {
                $prepared = $this->prepareCanonicalRows($runId, $user, $sourceProduct, $rows, $categoryIdMap, $accountIdMap, $payeeNameMap, $sameFingerprintOrdinals);
                $skipped += $prepared['skipped'];

                if ($prepared['canonicals'] === []) {
                    return;
                }

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
                $skipped += $counts['carried'];
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
     * @param  array<string, int>  $sameFingerprintOrdinals  tuple => highest ordinal handed out so far
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
        array &$sameFingerprintOrdinals,
    ): array {
        /** @var list<stdClass> $newRows */
        $newRows = [];
        /** @var list<CanonicalTransaction> $newCanonicals */
        $newCanonicals = [];
        $skipped = 0;

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $tuple = self::sameFingerprintTuple($row);
            $ordinal = $sameFingerprintOrdinals[$tuple] = ($sameFingerprintOrdinals[$tuple] ?? -1) + 1;

            $externalId = self::toString($row->source_external_id);
            $alreadyMapped = $this->sourceMapWriter->resolve($user, new SourceMapKey($sourceProduct, 'transaction', $externalId));

            if ($alreadyMapped !== null) {
                $skipped++;

                continue;
            }

            $canonical = $this->buildCanonicalTransaction($runId, $user, $sourceProduct, $row, $categoryIdMap, $accountIdMap, $payeeNameMap, $ordinal);

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
     * @return array{inserted: int, carried: int, splits: int}
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

        // Asked before the batch is recorded, because afterwards nothing tells
        // the row this run created from the one the reader has held for a year.
        // The RecordResult is discarded on purpose: the per-row fingerprint
        // lookup below names the exact staged row a collision dropped.
        /** @var Collection<string, int> $idsHeldBefore */
        $idsHeldBefore = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('fingerprint', array_values($fingerprintsByIndex))
            ->pluck('id', 'fingerprint');

        ($this->recordTransactions)($batch->canonicals, $user);

        /** @var Collection<string, int> $idsByFingerprint */
        $idsByFingerprint = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('fingerprint', array_values($fingerprintsByIndex))
            ->pluck('id', 'fingerprint');

        // Asked once per chunk rather than once per row, and only of the rows
        // that predate this run: a fingerprint match can name a row this device
        // already reconciled by hand, and the source's own cleared flag is not
        // allowed to take that back.
        $lockedIds = array_flip($this->statusQuery->reconciledIdsAmong(
            $user->id,
            array_map(static fn (mixed $id): int => self::toInt($id), array_values($idsHeldBefore->all())),
        ));

        $inserted = 0;
        $carried = 0;
        $splitsCreated = 0;

        foreach ($newRows as $idx => $row) {
            $description = $row->description !== null ? self::toString($row->description) : null;
            $fingerprint = $fingerprintsByIndex[$idx];
            $transactionId = $idsByFingerprint->has($fingerprint)
                ? self::toInt($idsByFingerprint->get($fingerprint))
                : null;

            if ($transactionId === null) {
                $this->unmappedItems->transactionNotCarried(
                    $runId,
                    $user,
                    $row,
                    $payeeNameMap,
                    CopyLine::of('migration::unmapped.reason.fingerprint_collision'),
                );

                continue;
            }

            $this->carryStatusAcross(
                $runId,
                $user,
                $row,
                $payeeNameMap,
                $transactionId,
                locked: isset($lockedIds[$transactionId]),
            );

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

            // A row whose fingerprint was already on the books was mapped, not
            // imported, and the results screen prints this number as "imported".
            if ($idsHeldBefore->has($fingerprint)) {
                $carried++;
            } else {
                $inserted++;
            }

            if ((bool) $row->is_split_parent && $this->createSplitLegs($runId, $user, $row, $transactionId, $categoryIdMap, $payeeNameMap)) {
                $splitsCreated++;
            }

            $this->mapPayeeToCounterparty($runId, $user, $sourceProduct, $row, $newCanonicals[$idx], $payeeNameMap, $resolvedPayees);
        }

        return ['inserted' => $inserted, 'carried' => $carried, 'splits' => $splitsCreated];
    }

    // CanonicalTransaction::toAttributes() hard-stamps 'cleared' for any
    // non-'manual' sourceFormat, so the staged flag is re-applied here — and
    // announced by the writer, because the create op RecordTransactions
    // captured a moment ago already carries the stamped value.
    /**
     * @param  array<string, string>  $payeeNameMap
     */
    private function carryStatusAcross(
        int $runId,
        User $user,
        stdClass $row,
        array $payeeNameMap,
        int $transactionId,
        bool $locked,
    ): void {
        if ($locked) {
            $this->unmappedItems->transactionNotCarried(
                $runId,
                $user,
                $row,
                $payeeNameMap,
                CopyLine::of('migration::unmapped.reason.reconciled_status_kept'),
            );

            return;
        }

        $staged = ClearedStatus::tryFrom(self::toString($row->cleared_status));

        if ($staged !== null) {
            $this->statusWriter->restateFromSource($user, $transactionId, $staged);
        }
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
     * @param  array<string, string>  $payeeNameMap
     */
    private function createSplitLegs(int $runId, User $user, stdClass $parentRow, int $transactionId, array $categoryIdMap, array $payeeNameMap): bool
    {
        $legRows = $this->db->connection()->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->where('parent_source_external_id', self::toString($parentRow->source_external_id))
            ->orderBy('id')
            ->get();

        /** @var list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}> $legs */
        $legs = [];
        $withoutCategory = 0;

        /** @var stdClass $legRow */
        foreach ($legRows as $legRow) {
            $legCategoryExternalId = $legRow->category_source_external_id;
            $legCategoryId = is_string($legCategoryExternalId) ? ($categoryIdMap[$legCategoryExternalId] ?? null) : null;

            if ($legCategoryId === null) {
                $withoutCategory++;

                continue;
            }

            $legs[] = [
                'id' => null,
                'category_id' => $legCategoryId,
                'settled_amount_minor' => self::toInt($legRow->settled_amount_minor),
                'note' => $legRow->description !== null ? self::toString($legRow->description) : null,
            ];
        }

        // transaction_splits.category_id is NOT NULL, so a leg with no category
        // cannot be carried at all. Keeping the rest would leave them short of
        // the parent, which throws only after the parent row is already in.
        if ($withoutCategory > 0) {
            $this->unmappedItems->transactionNotCarried($runId, $user, $parentRow, $payeeNameMap, CopyLine::plural(
                'migration::unmapped.reason.split_legs_without_category',
                $withoutCategory,
                [
                    'legs' => $withoutCategory + count($legs),
                    'uncategorized' => CopyParam::line('ledger::common.uncategorized'),
                ],
            ));
        }

        // Fewer than two legs is not a split, and a leg that lost its category
        // has already been reported above; both leave the parent uncarried.
        if ($withoutCategory > 0 || count($legs) < 2) {
            return false;
        }

        return $this->saveSplitLegs($runId, $user, $parentRow, $transactionId, $legs, $payeeNameMap);
    }

    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     * @param  array<string, string>  $payeeNameMap
     */
    private function saveSplitLegs(int $runId, User $user, stdClass $parentRow, int $transactionId, array $legs, array $payeeNameMap): bool
    {
        try {
            $this->splitSaver->save($user, $transactionId, $legs);
        } catch (SplitSumMismatchException) {
            // The sum is checked before any leg is written, so nothing partial
            // survives — but the parent is already in, so this cannot surface
            // as a failed run.
            $settledCurrency = self::toString($parentRow->settled_currency);
            $this->unmappedItems->transactionNotCarried($runId, $user, $parentRow, $payeeNameMap, CopyLine::of(
                'migration::unmapped.reason.split_sum_mismatch',
                [
                    'legs' => CopyParam::money(array_sum(array_column($legs, 'settled_amount_minor')), $settledCurrency),
                    'total' => CopyParam::money(self::toInt($parentRow->settled_amount_minor), $settledCurrency),
                ],
            ));

            return false;
        } catch (InvalidArgumentException) {
            // A shape the split writer will not store — legs that cancel to a
            // zero-net transaction are the one that reaches here from a real
            // export. One such row must not cost the reader the whole file.
            $this->unmappedItems->transactionNotCarried(
                $runId,
                $user,
                $parentRow,
                $payeeNameMap,
                CopyLine::of('migration::unmapped.reason.split_unstorable'),
            );

            return false;
        }

        return true;
    }

    // The staged spelling of what FingerprintComposer keys on, read off the
    // source so two devices importing one export group the rows alike. The
    // category is deliberately absent: it is the column the reader edits before
    // re-exporting, and this tuple exists to survive that edit.
    private static function sameFingerprintTuple(stdClass $row): string
    {
        return implode("\x1f", [
            self::toString($row->account_source_external_id),
            self::toString($row->posted_at),
            is_string($row->payee_source_external_id) ? $row->payee_source_external_id : '',
            (string) self::toInt($row->amount_minor),
            self::toString($row->currency),
        ]);
    }

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  array<string, int>  $accountIdMap
     * @param  array<string, string>  $payeeNameMap
     * @param  int  $sameFingerprintOrdinal  this row's position among the run's rows that would otherwise fingerprint identically
     */
    private function buildCanonicalTransaction(
        int $runId,
        User $user,
        string $sourceProduct,
        stdClass $row,
        array $categoryIdMap,
        array $accountIdMap,
        array $payeeNameMap,
        int $sameFingerprintOrdinal,
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
            $isTransfer => $amountMinor < 0 ? TransactionType::TransferOut->value : TransactionType::TransferIn->value,
            $amountMinor > 0 => TransactionType::Income->value,
            $amountMinor < 0 => TransactionType::Expense->value,
            default => TransactionType::Adjustment->value,
        };

        $payeeExternalId = $row->payee_source_external_id;
        $counterpartyName = is_string($payeeExternalId) ? ($payeeNameMap[$payeeExternalId] ?? null) : null;
        $counterpartyNormalized = $this->counterpartyKey->forName($counterpartyName, $user->id);

        $categoryExternalId = $row->category_source_external_id;
        $categoryId = is_string($categoryExternalId) ? ($categoryIdMap[$categoryExternalId] ?? null) : null;

        $postedAt = CarbonImmutable::parse(self::toString($row->posted_at));

        // Every postedAt is midnight (no source carries a time-of-day); the
        // offset keeps two same-day rows off one fingerprint. It counts within
        // the fingerprint's own tuple, never the staging row's database id: that
        // id is minted per run, so a re-export arrived as a second copy.
        $bookedAt = $postedAt->addSeconds($sameFingerprintOrdinal % Duration::Day->seconds());

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
            counterpartyName: $counterpartyName,
            counterpartyIban: $counterpartyIban,
            counterpartyNormalized: $counterpartyNormalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $row->description !== null ? self::toString($row->description) : null,
            categoryId: $categoryId,
            sourceFormat: MigrationSourceFormat::forProduct($sourceProduct),
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

    // Held for the length of ONE promote() and cleared at its head. Every
    // promoted row files itself under the same run, and asking per row cost a
    // statement each across a ledger the size of a life.
    private ?int $importRunId = null;

    /**
     * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
     */
    private function migrationImportRunId(User $user, string $sourceProduct): int
    {
        if ($this->importRunId !== null) {
            return $this->importRunId;
        }

        $connection = $this->db->connection();
        $match = ['user_id' => $user->id, 'source_format' => MigrationSourceFormat::forProduct($sourceProduct)];

        $existingId = IdReadBack::orNull($connection, 'import_runs', $match);

        if ($existingId !== null) {
            return $this->importRunId = $existingId;
        }

        $now = $this->clock->now();

        $connection->table('import_runs')->insert([
            ...$match,
            'raw_file_path' => 'migration',
            'sha256' => hash('sha256', 'migration:'.$sourceProduct),
            'uploaded_at' => $now,
            'status' => MigrationRunStatus::Confirmed->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The id is read back by the match, never taken from insertGetId():
        // lastInsertId() is per connection, and the sidebar's badge listener
        // writes a `cache` row from inside this INSERT's own event. Every row
        // this promote files would then name an import run that does not exist.
        return $this->importRunId = IdReadBack::of($connection, 'import_runs', $match);
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
                $this->unmappedItems->goalNotCarried($runId, $user, $categoryExternalId, $name, CopyLine::of('migration::unmapped.reason.goal_without_target_date'));

                continue;
            }

            // The name is a required field exactly like the target date above,
            // and a staged file can be missing either one.
            if (trim($name) === '') {
                $this->unmappedItems->goalNotCarried($runId, $user, $categoryExternalId, $name, CopyLine::of('migration::unmapped.reason.goal_without_name'));

                continue;
            }

            $targetCurrency = self::toString($row->target_currency);

            $goal = $this->goalWriter->save(
                $user,
                $name,
                MoneyInput::toDecimalString(self::toInt($row->target_minor), $targetCurrency),
                $targetDate,
                $targetCurrency,
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

    // Same group, same name, same kind is the category the reader already has,
    // and a second one renders byte-identically in every picker. Matched on the
    // stored name AND on the reader's translation of it, so an export written
    // in their own language lands on the seeded row rather than beside it.
    /**
     * @link ../../../../.docs/features/ledger/category-display-names.md#which-one-of-them-is-it-categorypathname
     */
    private function categoryAlreadyAtThisPath(User $user, ?int $parentId, string $name, string $kind): ?int
    {
        $query = $this->db->connection()->table('categories')
            ->where(static function (QueryBuilder $scope) use ($user): void {
                $scope->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->where('kind', $kind);

        $siblings = ($parentId === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId))
            ->orderBy('id')
            ->get(['id', ...CategoryDisplayName::bareColumns()]);

        /** @var stdClass $sibling */
        foreach ($siblings as $sibling) {
            if ($name === self::toString($sibling->name) || $name === CategoryDisplayName::fromRow($sibling)) {
                return self::toInt($sibling->id);
            }
        }

        return null;
    }

    private function uniqueCategorySlug(User $user, string $name): string
    {
        $connection = $this->db->connection();

        return UniqueSlug::walk(
            UniqueSlug::slugify($name, self::CATEGORY_SLUG_FALLBACK),
            fn (string $slug): bool => ! $connection->table('categories')
                ->where('user_id', $user->id)
                ->where('slug', $slug)
                ->exists(),
        );
    }

    private static function syntheticIban(string $sourceExternalId): string
    {
        return 'MIG'.strtoupper(hash('crc32b', $sourceExternalId));
    }
}
