<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Money;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Migration\Public\Dto\ConflictDto;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * The 3-way-merge comparator (Req 10, D-11/D-12/D-13 — RESEARCH.md § "3-way
 * merge algorithm", THE authoritative spec; there is no codebase analog).
 *
 * For every entity already staged in a NEWLY-parsed re-import run (`$newRunId`)
 * that ALSO has a persistent `migration_source_map` row from an earlier
 * confirmed import, this reads the three legs of the merge:
 *
 *   S_new = the newly-parsed source value (from `migration_staging_*`)
 *   B     = the baseline value stored at the entity's LAST import
 *           (`migration_import_baseline`)
 *   C     = the CURRENT beatrax value, read fresh from the real domain table
 *
 * and applies the rule verbatim:
 *
 *   S_new == B                 -> skip (source unchanged, neither set)
 *   S_new != B AND C == B      -> apply-set (source changed, beatrax untouched)
 *   S_new != B AND C != B      -> conflict-set (BOTH diverged from baseline)
 *
 * An entity with NO existing `migration_source_map` row is a brand-new source
 * row this resolver does not touch at all — `PromoteStagingToDomain::promote()`
 * 's own per-entity resolve-gate already creates it normally (Req 9
 * idempotency holds unchanged for that path).
 *
 * Scope (mirrors `ThreeWayMergeReconciliationTest`'s own documented scope
 * note): budget-assignment reconciliation is the fully-tested, concretely
 * worked example from RESEARCH.md and is wired end-to-end into
 * `PromoteStagingToDomain`'s skip-list. Category-name, account-name, and
 * transaction-description reconciliation follow the IDENTICAL algorithm
 * against a different entity kind and are implemented for completeness (the
 * plan's own "Per-entity apply()" contract), applied directly by
 * `CheckForUpdates` via a plain table update.
 *
 * Transaction AMOUNT reconciliation (13.5-VERIFICATION.md Req 10 gap-fix)
 * follows the identical algorithm against `transactions.amount_minor`,
 * Money-aware exactly like budget_assignment. It is deliberately restricted
 * to non-split, non-split-parent rows (`is_split_parent = false` AND
 * `parent_source_external_id IS NULL`): a split parent's `amount_minor` is
 * not the invariant `SaveTransactionSplit::save()` actually enforces (that
 * compares leg `settled_amount_minor` against the parent's own
 * `settled_amount_minor`), so reconciling a split parent's native amount
 * independently of its legs would risk a silently inconsistent row — out of
 * this gap-fix's tight scope, left for a future plan. `settled_amount_minor`
 * (the cross-currency settled leg) and the transaction DATE are likewise
 * NOT reconciled here (DOCUMENTED FOLLOW-UP): a date change also shifts the
 * fingerprint tuple's `posted_at`/`booked_at` legs and the
 * `transactions_fingerprint_uq` composite unique index simultaneously,
 * which is materially higher blast radius than a single-column amount swap
 * and was judged out of this gap-fix's tight scope. Transaction date/
 * category-assignment and payee/goal reconciliation remain unimplemented
 * (DOCUMENTED ASSUMPTION carried over from Plan 07): they carry higher blast
 * radius (fingerprint invariants, `GoalWriter` validation) than this plan's
 * test scope justifies; a source-side change to those fields on an
 * already-promoted entity currently falls through unreconciled until a
 * future plan extends this resolver.
 *
 * Money comparisons for `budget_assignment` use `Brick\Money\Money` value
 * equality (currency-aware), not raw int comparison, per this plan's own
 * acceptance criteria — a `MoneyMismatchException` (genuinely different
 * currency between the two legs) is treated as "not equal" rather than
 * bubbling up, since a currency change is itself a value change.
 *
 * All reads are raw `DatabaseManager` query-builder calls, explicitly
 * `user_id`-scoped (never a chained dynamic-Eloquent call — PHPStan L10
 * strict `staticMethod.dynamicCall`), matching `SourceMapWriter`'s/
 * `PreviewSummaryBuilder`'s established discipline.
 *
 * CRYPT-01 / 14.1-AUDIT.md Cluster 4 (T-14.1-14a): `reconcileTransactionDescriptions()`
 * decrypts the LIVE stored `transactions.description` before the equality
 * compare against the plaintext `$baseline` snapshot — the stored value is
 * ciphertext once encryption is enabled for the user, and comparing it raw
 * would register a spurious conflict on every re-run (mirrors
 * `SetTransactionNote`'s decrypt-before-compare fix). Investigation finding:
 * the 14.1-AUDIT.md fix sketch also flagged an "analogous counterparty-name
 * reconciliation earlier in the same file" — no such site exists in this
 * class. `reconcileCategories()`/`reconcileAccounts()` compare a `name`
 * column, but `categories.name`/`accounts.name` are NOT
 * `SensitiveFieldRegistry`-listed (only `counterparties.display_name`/
 * `merchant_name`/`iban` are), so those two methods are correctly
 * unaffected and were left unchanged.
 */
final class ThreeWayMergeResolver
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function resolve(int $newRunId, User $user, string $sourceProduct): MergeDecision
    {
        /** @var list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}> $applies */
        $applies = [];
        /** @var list<ConflictDto> $conflicts */
        $conflicts = [];

        $this->reconcileBudgetAssignments($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileCategories($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileAccounts($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileTransactionDescriptions($newRunId, $user, $sourceProduct, $applies, $conflicts);
        $this->reconcileTransactionAmounts($newRunId, $user, $sourceProduct, $applies, $conflicts);

        return new MergeDecision($applies, $conflicts);
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileBudgetAssignments(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toStr($row->source_category_external_id);
            $periodStart = self::toStr($row->period_start);
            $sourceExternalId = $categoryExternalId.'|'.$periodStart;
            $currency = self::toStr($row->currency);
            $sNewMinor = self::toInt($row->budgeted_minor);

            $map = $this->findMap($user, $sourceProduct, 'budget_assignment', $sourceExternalId);
            if ($map === null) {
                continue; // brand-new entity — normal promote() creates it.
            }

            $baselineRaw = $this->baselineValue($user, self::toInt($map->id), 'budgeted_minor');
            if ($baselineRaw === null) {
                continue; // no recorded baseline — cannot 3-way merge, leave to the unconditional promote() path.
            }
            $baselineMinor = self::toInt($baselineRaw);

            $categoryId = $this->beatraxId($user, $sourceProduct, 'category', $categoryExternalId);
            if ($categoryId === null) {
                continue;
            }

            // Fresh read of the CURRENT beatrax value (D-06 absence == 0,
            // mirroring PromoteStagingToDomain's own zero-minor convention).
            $currentValue = $connection->table('envelope_assignments')
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->where('period_start', $periodStart)
                ->value('assigned_minor');
            $currentMinor = self::toInt($currentValue);

            if (self::moneyEquals($sNewMinor, $currency, $baselineMinor, $currency)) {
                continue; // source unchanged since last import.
            }

            if (self::moneyEquals($currentMinor, $currency, $baselineMinor, $currency)) {
                $applies[] = [
                    'entityType' => 'budget_assignment',
                    'sourceExternalId' => $sourceExternalId,
                    'fields' => ['budgeted_minor' => $sNewMinor],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: 'budget_assignment',
                sourceExternalId: $sourceExternalId,
                fieldName: 'budgeted_minor',
                localValue: $currentMinor,
                sourceValue: $sNewMinor,
                baselineValue: $baselineMinor,
                currency: $currency,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileCategories(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_categories')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toStr($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'category', $externalId);
            if ($map === null) {
                continue;
            }

            $baseline = $this->baselineValue($user, self::toInt($map->id), 'name');
            if ($baseline === null) {
                continue;
            }

            $categoryId = self::toInt($map->beatrax_id);
            $sNew = self::toStr($row->name);
            $current = self::toStr(
                $connection->table('categories')->where('id', $categoryId)->where('user_id', $user->id)->value('name')
            );

            if ($sNew === $baseline) {
                continue;
            }

            if ($current === $baseline) {
                $applies[] = [
                    'entityType' => 'category',
                    'sourceExternalId' => $externalId,
                    'fields' => ['name' => $sNew],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: 'category',
                sourceExternalId: $externalId,
                fieldName: 'name',
                localValue: $current,
                sourceValue: $sNew,
                baselineValue: $baseline,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileAccounts(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_accounts')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toStr($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'account', $externalId);
            if ($map === null) {
                continue;
            }

            $baseline = $this->baselineValue($user, self::toInt($map->id), 'name');
            if ($baseline === null) {
                continue;
            }

            $accountId = self::toInt($map->beatrax_id);
            $sNew = self::toStr($row->name);
            $current = self::toStr(
                $connection->table('accounts')->where('id', $accountId)->where('user_id', $user->id)->value('name')
            );

            if ($sNew === $baseline) {
                continue;
            }

            if ($current === $baseline) {
                $applies[] = [
                    'entityType' => 'account',
                    'sourceExternalId' => $externalId,
                    'fields' => ['name' => $sNew],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: 'account',
                sourceExternalId: $externalId,
                fieldName: 'name',
                localValue: $current,
                sourceValue: $sNew,
                baselineValue: $baseline,
            );
        }
    }

    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileTransactionDescriptions(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->whereNull('parent_source_external_id')
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toStr($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'transaction', $externalId);
            if ($map === null) {
                continue;
            }

            $baseline = $this->baselineValue($user, self::toInt($map->id), 'description');
            if ($baseline === null) {
                continue;
            }

            $transactionId = self::toInt($map->beatrax_id);
            $sNew = $row->description !== null ? self::toStr($row->description) : '';
            $currentRaw = $connection->table('transactions')->where('id', $transactionId)->where('user_id', $user->id)->value('description');
            // CRYPT-01 / T-14.1-14a decrypt-before-compare: the LIVE stored
            // value is ciphertext under an encrypted user — comparing it
            // raw against the plaintext $baseline would register a
            // spurious conflict on every re-run (ciphertext never equals
            // plaintext). decryptValue() is a documented pass-through
            // no-op for a plaintext user/legacy value, so this is safe for
            // both populations. Guarded on is_string so a NULL stored
            // description is never handed to the codec.
            $current = is_string($currentRaw)
                ? $this->codec->decryptValue('transactions', 'description', $currentRaw, $user->id, $this->session)['value']
                : self::toStr($currentRaw);

            if ($sNew === $baseline) {
                continue;
            }

            if ($current === $baseline) {
                $applies[] = [
                    'entityType' => 'transaction',
                    'sourceExternalId' => $externalId,
                    'fields' => ['description' => $sNew],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: 'transaction',
                sourceExternalId: $externalId,
                fieldName: 'description',
                localValue: $current,
                sourceValue: $sNew,
                baselineValue: $baseline,
            );
        }
    }

    /**
     * Req 10 gap-fix (13.5-VERIFICATION.md): reconciles a plain transaction's
     * native `amount_minor` — Money-aware, exactly like `budget_assignment`
     * above. Restricted to non-split rows (see class docblock) to avoid the
     * split-leg-sum invariant `SaveTransactionSplit::save()` actually
     * enforces against `settled_amount_minor`, a different column this
     * method never touches.
     *
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    private function reconcileTransactionAmounts(int $newRunId, User $user, string $sourceProduct, array &$applies, array &$conflicts): void
    {
        $connection = $this->db->connection();

        $rows = $connection->table('migration_staging_transactions')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $newRunId)
            ->whereNull('parent_source_external_id')
            ->where('is_split_parent', false)
            ->get();

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $externalId = self::toStr($row->source_external_id);
            $map = $this->findMap($user, $sourceProduct, 'transaction', $externalId);
            if ($map === null) {
                continue;
            }

            $baselineRaw = $this->baselineValue($user, self::toInt($map->id), 'amount_minor');
            if ($baselineRaw === null) {
                continue; // no recorded baseline for this field — leave to the unconditional promote() path.
            }
            $baselineMinor = self::toInt($baselineRaw);

            $transactionId = self::toInt($map->beatrax_id);
            $sNewMinor = self::toInt($row->amount_minor);
            $sourceCurrency = self::toStr($row->currency);

            /** @var stdClass|null $currentRow */
            $currentRow = $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->first(['amount_minor', 'currency']);

            if ($currentRow === null) {
                continue;
            }

            $currentCurrency = self::toStr($currentRow->currency);
            $currentMinor = self::toInt($currentRow->amount_minor);

            if (self::moneyEquals($sNewMinor, $sourceCurrency, $baselineMinor, $sourceCurrency)) {
                continue; // source unchanged since last import.
            }

            // WR-13: tag the baseline leg with the currency the LIVE transaction
            // is recorded under, not $sourceCurrency. The baseline captured the
            // prior same-transaction minor amount, so its currency provenance is
            // $currentCurrency. Tagging it $sourceCurrency made moneyEquals catch
            // a MoneyMismatchException and return false whenever the live
            // transaction's currency differed from the incoming source currency,
            // spuriously flagging a conflict on a currency-stable, amount-stable
            // row.
            if (self::moneyEquals($currentMinor, $currentCurrency, $baselineMinor, $currentCurrency)) {
                $applies[] = [
                    'entityType' => 'transaction',
                    'sourceExternalId' => $externalId,
                    'fields' => ['amount_minor' => $sNewMinor],
                ];

                continue;
            }

            $conflicts[] = new ConflictDto(
                entityType: 'transaction',
                sourceExternalId: $externalId,
                fieldName: 'amount_minor',
                localValue: $currentMinor,
                sourceValue: $sNewMinor,
                baselineValue: $baselineMinor,
                currency: $currentCurrency,
            );
        }
    }

    private function findMap(User $user, string $sourceProduct, string $entityType, string $sourceExternalId): ?stdClass
    {
        $row = $this->db->connection()->table('migration_source_map')
            ->where('user_id', $user->id)
            ->where('source_product', $sourceProduct)
            ->where('source_entity_type', $entityType)
            ->where('source_external_id', $sourceExternalId)
            ->first(['id', 'beatrax_id']);

        return $row instanceof stdClass ? $row : null;
    }

    private function beatraxId(User $user, string $sourceProduct, string $entityType, string $sourceExternalId): ?int
    {
        $map = $this->findMap($user, $sourceProduct, $entityType, $sourceExternalId);

        return $map !== null ? self::toInt($map->beatrax_id) : null;
    }

    private function baselineValue(User $user, int $mapId, string $field): ?string
    {
        $value = $this->db->connection()->table('migration_import_baseline')
            ->where('migration_source_map_id', $mapId)
            ->where('field_name', $field)
            ->where('user_id', $user->id)
            ->value('baseline_value');

        return is_string($value) ? $value : null;
    }

    private static function moneyEquals(int $aMinor, string $aCurrency, int $bMinor, string $bCurrency): bool
    {
        try {
            return Money::ofMinor($aMinor, $aCurrency)->isEqualTo(Money::ofMinor($bMinor, $bCurrency));
        } catch (MoneyMismatchException) {
            return false;
        }
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
