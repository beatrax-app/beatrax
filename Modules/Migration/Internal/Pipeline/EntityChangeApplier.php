<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Shared "write one reconciled field to the domain" path (extracted from
 * `CheckForUpdates` during the 13.5-HUMAN-UAT.md Test 3c gap-fix so
 * `ConfirmMigration`'s new take-source apply step reuses the SAME writer
 * routing/fingerprint-safety/baseline-advance logic rather than
 * duplicating it):
 *
 *   - `apply()` — writes a category/account rename or a transaction
 *     description/amount to the domain, resolving the beatrax entity id via
 *     `SourceMapWriter`, then advances that entity's baseline snapshot to
 *     the newly-applied value. `budget_assignment` is deliberately NOT
 *     handled here — it goes through `PromoteStagingToDomain::
 *     promoteBudgetAssignments()`'s own unconditional-apply-by-value path
 *     instead (its skip-list is what decides whether a given row is
 *     touched), so routing it through `apply()` as well would double-apply.
 *   - `advanceBaseline()` — pins a conflict's baseline to a specific value
 *     (local OR source) without writing anything to the domain — used for
 *     every keep-local-resolved conflict (of ANY entity type, including
 *     `budget_assignment`) so an already-decided divergence does not
 *     re-flag on the next reconciliation run (RESEARCH.md's baseline-advance
 *     rule).
 *   - `applyTransactionAmount()` — the fingerprint-safe transaction-amount
 *     writer (Req 10 gap-fix, 13.5-07-GAPFIX.md): `amount_minor` is part of
 *     the SHA-256 fingerprint tuple AND the `transactions_fingerprint_uq`
 *     composite unique index, so it cannot be a bare column update.
 */
final class EntityChangeApplier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SourceMapWriter $sourceMapWriter,
        private readonly FingerprintComposer $fingerprints,
        private readonly LoggerInterface $logger,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Applies one non-`budget_assignment` field change to the domain.
     * Returns false when the write could not happen (no source-map entry
     * yet, or — for a transaction amount — a fingerprint collision) so the
     * caller can decide how to surface that (CheckForUpdates records a
     * visible collision row; ConfirmMigration simply leaves the conflict
     * unresolved rather than crashing the confirm attempt).
     *
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public function apply(User $user, string $sourceProduct, string $entityType, string $sourceExternalId, array $fields): bool
    {
        $table = match ($entityType) {
            'category' => 'categories',
            'account' => 'accounts',
            'transaction' => 'transactions',
            default => null,
        };

        if ($table === null) {
            return false;
        }

        $beatraxId = $this->sourceMapWriter->resolve($user, $sourceProduct, $entityType, $sourceExternalId);
        if ($beatraxId === null) {
            return false;
        }

        if ($entityType === 'transaction' && array_key_exists('amount_minor', $fields)) {
            $newAmountMinor = $fields['amount_minor'];
            if (! (is_int($newAmountMinor) && $this->applyTransactionAmount($user, $beatraxId, $newAmountMinor))) {
                return false;
            }
        } else {
            // CRYPT-01 / T-14.1-14b: route $fields through the
            // SensitiveColumnCodec before the raw update() (mirrors
            // TagTransaction's encrypt-before-write) — a reconciled
            // transaction.description (or any future SensitiveFieldRegistry
            // column reconciled through this path) must never land as
            // plaintext in an at-rest-encrypted column. encryptAttrs() only
            // touches registry-listed columns and is a no-op for
            // non-sensitive fields (category/account `name`) and for a
            // plaintext user, so this call is safe uniformly across every
            // entity kind apply() handles. The UNENCRYPTED $fields are
            // still what gets snapshotted into migration_import_baseline
            // below (record()) — the baseline compare in
            // ThreeWayMergeResolver is always plaintext-to-decrypted-plaintext.
            $storedFields = $this->codec->encryptAttrs($table, $fields, $user->id, $this->session);

            $this->db->connection()->table($table)
                ->where('id', $beatraxId)
                ->where('user_id', $user->id)
                ->update($storedFields);
        }

        $this->sourceMapWriter->record(
            $user,
            $sourceProduct,
            $entityType,
            $sourceExternalId,
            null,
            self::beatraxEntityType($entityType),
            $beatraxId,
            $fields,
        );

        return true;
    }

    /**
     * Pins a conflict's baseline to `$value` (the LOCAL value for a
     * keep-local resolution) WITHOUT writing anything to the domain — the
     * beatrax value already IS `$value`, so only the "what does the next
     * reconciliation compare against" bookkeeping needs updating. Works
     * uniformly across every entity type this resolver reconciles,
     * including `budget_assignment` (its persistent `migration_source_map`
     * row is keyed by the SAME `{categoryExternalId}|{period_start}`
     * composite `sourceExternalId` `ThreeWayMergeResolver` already uses).
     */
    public function advanceBaseline(User $user, string $sourceProduct, string $entityType, ?string $sourceExternalId, string $fieldName, string|int $value): void
    {
        if ($sourceExternalId === null) {
            return;
        }

        $beatraxId = $this->sourceMapWriter->resolve($user, $sourceProduct, $entityType, $sourceExternalId);
        if ($beatraxId === null) {
            return; // defensive: a conflict only ever exists for an already-mapped entity.
        }

        $this->sourceMapWriter->record(
            $user,
            $sourceProduct,
            $entityType,
            $sourceExternalId,
            null,
            self::beatraxEntityType($entityType),
            $beatraxId,
            [$fieldName => $value],
        );
    }

    /**
     * Updates a transaction's `amount_minor` AND recomputes its stored
     * `fingerprint` in the same statement, so the second-layer SHA-256
     * idempotency guard (`FingerprintComposer`) never drifts from the row's
     * actual content. Returns false (no write performed) on a genuine
     * `transactions_fingerprint_uq` collision rather than letting the raw
     * `QueryException` bubble out of the caller.
     *
     * CRYPT-01 / 14.1-AUDIT.md Cluster 4 (T-14.1-14c) INVESTIGATION FINDING
     * (the flagged lower-confidence sub-issue): `FingerprintComposer::compose()`
     * hashes the tuple `user_id | account_id | posted_at | booked_at |
     * amount_minor | currency | counterparty_normalized` — it does NOT
     * consume `counterparty_name`, `counterparty_iban`, or `description`
     * bytes at all. `counterparty_normalized` is the only counterparty-ish
     * field in the tuple, and it is deliberately NOT
     * `SensitiveFieldRegistry`-listed (D-02b) — it is always plaintext,
     * regardless of whether the user has encryption enabled. Therefore the
     * raw reads of `counterparty_name`/`counterparty_iban`/`description`
     * below (ciphertext under an encrypted user) are stored on the
     * `CanonicalTransaction` DTO but never reach `compose()`'s input tuple,
     * so the recomputed fingerprint is IDENTICAL to what a plaintext
     * re-import would produce for the same logical row — no decrypt is
     * needed here for fingerprint correctness, and
     * `transactions_fingerprint_uq` idempotency is NOT at risk. This is
     * proven by `MigrationEntityChangeApplierEncryptionTest`'s idempotency
     * regression test rather than left as an assumption.
     */
    public function applyTransactionAmount(User $user, int $transactionId, int $newAmountMinor): bool
    {
        $connection = $this->db->connection();

        /** @var stdClass|null $row */
        $row = $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return false;
        }

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: self::toInt($row->account_id),
            type: self::toStr($row->type),
            postedAt: CarbonImmutable::parse(self::toStr($row->posted_at)),
            bookedAt: CarbonImmutable::parse(self::toStr($row->booked_at)),
            valueDate: CarbonImmutable::parse(self::toStr($row->value_date)),
            amountMinor: $newAmountMinor,
            currency: self::toStr($row->currency),
            settledAmountMinor: self::toInt($row->settled_amount_minor),
            settledCurrency: self::toStr($row->settled_currency),
            fxRateUsed: $row->fx_rate_used !== null ? self::toStr($row->fx_rate_used) : null,
            counterpartyName: $row->counterparty_name !== null ? self::toStr($row->counterparty_name) : null,
            counterpartyIban: $row->counterparty_iban !== null ? self::toStr($row->counterparty_iban) : null,
            counterpartyNormalized: self::toStr($row->counterparty_normalized),
            normalizationVersion: self::toInt($row->normalization_version),
            description: $row->description !== null ? self::toStr($row->description) : null,
            categoryId: $row->category_id !== null ? self::toInt($row->category_id) : null,
            sourceFormat: self::toStr($row->source_format),
            importRunId: self::toInt($row->import_run_id),
            sourceRowIndex: self::toInt($row->source_row_index),
            sourceRef: $row->source_ref !== null ? self::toStr($row->source_ref) : null,
        );

        $fingerprint = $this->fingerprints->compose($canonical);

        try {
            $connection->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update([
                    'amount_minor' => $newAmountMinor,
                    'fingerprint' => $fingerprint,
                ]);
        } catch (QueryException $e) {
            // WR-03: only a genuine fingerprint-uniqueness violation is a
            // benign, expected collision — this update touches ONLY
            // amount_minor/fingerprint on an already-existing row (scoped
            // by id+user_id), so any OTHER QueryException (transient
            // connection error, disk-full, a future schema change adding a
            // NOT NULL/CHECK constraint on one of these columns) must NOT
            // be silently reclassified as "it's just a collision" — that
            // would mask the real failure from both the user and anyone
            // debugging a bug report. The raw exception is always logged
            // so a genuinely unexpected failure is never swallowed
            // invisibly, even in the collision case.
            $this->logger->warning('EntityChangeApplier: applyTransactionAmount() query failed.', [
                'transaction_id' => $transactionId,
                'user_id' => $user->id,
                'is_fingerprint_collision' => self::isFingerprintUniqueViolation($e),
                'exception_message' => $e->getMessage(),
            ]);

            if (! self::isFingerprintUniqueViolation($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * True only for a genuine unique-constraint violation against one of
     * the two fingerprint-related indexes this UPDATE could hit —
     * `transactions_fingerprint_uq` (the composite `user_id, account_id,
     * posted_at, amount_minor, currency, counterparty_normalized,
     * source_ref` index — `amount_minor` is part of this tuple) or
     * `transactions_fingerprint_sha_uq` (`user_id, fingerprint`). Every
     * driver surfaces SQLSTATE 23000 for a unique violation (mirrors
     * `CreateCategorizationRule::isUniqueViolation()`'s identical
     * cross-driver check) — that alone is NOT enough here, since a 23000
     * could in principle come from an unrelated constraint. This project
     * is SQLite-only (CLAUDE.md), and SQLite's own error message lists the
     * conflicting COLUMN names, not the index name (verified empirically:
     * "UNIQUE constraint failed: transactions.user_id,
     * transactions.account_id, ..., transactions.amount_minor, ..." for
     * the composite index, "UNIQUE constraint failed: transactions.user_id,
     * transactions.fingerprint" for the SHA index) — so the message is
     * additionally required to reference `transactions.fingerprint` or
     * `transactions.amount_minor` (the two columns this specific UPDATE
     * writes that could trip either index), OR — for forward-compatibility
     * with a driver that names the constraint instead — one of the two
     * index names themselves. An unrelated 23000 (e.g. a NOT NULL/CHECK
     * violation on some other column) matches none of these and is
     * re-thrown.
     */
    private static function isFingerprintUniqueViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'transactions.fingerprint')
            || str_contains($message, 'transactions.amount_minor')
            || str_contains($message, 'transactions_fingerprint_uq')
            || str_contains($message, 'transactions_fingerprint_sha_uq');
    }

    private static function beatraxEntityType(string $entityType): string
    {
        return match ($entityType) {
            'budget_assignment' => 'envelope_assignment',
            default => $entityType,
        };
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
