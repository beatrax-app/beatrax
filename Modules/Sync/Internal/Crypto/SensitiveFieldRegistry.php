<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * Single source-of-truth enumerating the (table, field) pairs Phase 14's
 * two encryption hooks (the op-log `value`-column path, Plan 03, and the
 * direct-write/import projection-column path, Plan 04) treat as sensitive
 * content requiring GDK-encryption at rest.
 *
 * Scope is locked by CONTEXT.md's D-02b (2026-07-09 scope_resolution),
 * itself derived from 14-RESEARCH.md's Sensitive-Column Inventory:
 *
 *   transactions.description, transactions.counterparty_name,
 *   transactions.counterparty_iban, transactions.raw_payload,
 *   transactions.note, counterparties.display_name,
 *   counterparties.merchant_name, counterparties.iban,
 *   tax_transaction_tags.note, transaction_splits.note.
 *
 * DELIBERATELY EXCLUDED (D-02a): `transactions.amount_minor`,
 * `transactions.settled_amount_minor`, and `transactions.fx_rate_used` are
 * NEVER encrypted. At least eleven query classes (AccountBalanceQuery,
 * ThisPeriodAtAGlanceQuery, SpendByCategoryQuery, CalendarQuery,
 * PotBalanceQuery, TaxTagQuery, SearchQuery, CounterpartyProfileQuery,
 * CounterpartyIndexQuery, BudgetProgressQuery, EnvelopeBalanceQuery)
 * perform SQL-side SUM()/GROUP BY over these columns — SQLite cannot
 * aggregate ciphertext, and rewriting every aggregation to sum in PHP is an
 * out-of-scope, phase-scale rewrite (14-RESEARCH.md Pitfall 1). Encrypting
 * amounts would silently break the app's core dashboard/reporting surface.
 *
 * DEFERRED (see 14-CONTEXT.md <deferred>, not this phase):
 * `counterparties.metadata` (JSON) and `saved_reports.definition` (JSON) —
 * plausibly sensitive but lower-certainty and out of the locked D-02b set;
 * revisit in a future hardening phase.
 *
 * KNOWINGLY-ACCEPTED PLAINTEXT DERIVATIVE (WR-17):
 * `recurring_series.cluster_counterparty_key` stores a decrypted payer IBAN (or
 * normalized-description fallback) verbatim as a DETERMINISTIC clustering/lookup
 * key. Random-nonce ciphertext cannot be a stable WHERE key, so this column is
 * deliberately excluded from encryption here. A future hardening replaces it
 * with a keyed HMAC / blind-index (deterministic, non-reversible) — that change
 * needs a stable key source AND a migration of existing cluster keys to avoid
 * splitting series, so it is a reviewed, tracked exception rather than an
 * oversight. See `Modules\Recurring\Internal\Detectors\IncomeSeriesDetector`.
 *
 * KNOWINGLY-ACCEPTED PLAINTEXT SINK (WR-10):
 * `migration_import_baseline.baseline_value` snapshots the plaintext value of a
 * reconciled `transactions.description` (and other fields) for the three-way
 * merge compare in `ThreeWayMergeResolver`. The resolver relies on the baseline
 * being plaintext, and it only covers reconciliation-touched rows. Encrypting it
 * (and decrypting in `ThreeWayMergeResolver::baselineValue`) is viable — it is a
 * stored compare value, not a lookup key — but changes reconciliation-correctness
 * paths, so it is a reviewed, tracked exception deferred to a future hardening.
 * See `Modules\Migration\Internal\Pipeline\EntityChangeApplier`.
 *
 * KNOWINGLY-ACCEPTED PLAINTEXT SINK (CR-03, code-review 14.1):
 * `pending_enrichment_conflicts.stored_value` / `.incoming_value` hold the
 * decrypted `counterparty_name`/`description` of a held receipt-enrichment
 * conflict (JSON-wrapped scalars) until the user resolves the toast. This is the
 * ORIGINAL, deliberate D-02b decision — `ApplyEnrichments::holdConflicts()`
 * receives already-decrypted values so the toast never renders ciphertext, and
 * `ApplyEnrichmentsEncryptionTest` + `ReceiptConflictResolutionTest` pin the
 * plaintext round-trip (writer → `ReceiptConflictQuery` → `ApplyReceiptConflict-
 * Resolution`, including tolerant delete of malformed rows). A code review flagged
 * the at-rest exposure; encrypting the two columns (and decrypting at both read
 * sites) is viable but reverses a locked, tested contract, so it is recorded here
 * as a reviewed, tracked exception deferred to a future hardening rather than
 * changed silently. See `Modules\Import\Public\Actions\ApplyEnrichments`.
 *
 * Both a static accessor (columns()) and an instance method (isSensitive())
 * are provided, mirroring Modules\Core\Public\Services\SecretsColumnRegistry's
 * shape — DI consumers (OpLogWriter, the Sync Public SensitiveColumnCodec)
 * inject the service and call isSensitive(); code that runs before the
 * container is available (arch tests, migrations) uses the static accessor.
 */
final class SensitiveFieldRegistry
{
    /**
     * Enumerate every {table}.{column} pair the D-02b-locked encryption
     * boundary covers. New entries land here after an explicit CONTEXT.md
     * scope decision — this registry never grows silently.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'transactions.note',
            'transactions.description',
            'transactions.counterparty_name',
            'transactions.counterparty_iban',
            'transactions.raw_payload',
            'counterparties.display_name',
            'counterparties.merchant_name',
            'counterparties.iban',
            'tax_transaction_tags.note',
            'transaction_splits.note',
            // 18-04 (D-11/D-12/D-13): notification content columns. `id`,
            // `user_id`, `created_at`, `read_at`, `dismissed_at`, and `state`
            // are deliberately NOT listed — the PK is matched in dedup
            // `WHERE` clauses and the timestamps drive KEK-less pruning /
            // unread counts; encrypting any of them breaks Req 1 and Req 14.
            'notifications.title',
            'notifications.body',
            'notifications.params',
            'notifications.trigger_type',
        ];
    }

    /**
     * DI-shim surface — constructor-injected consumers (OpLogWriter's
     * per-field encrypt-on-write hook, the Sync Public
     * SensitiveColumnCodec's direct-write encrypt/decrypt hook) call this
     * instance method rather than the static accessor directly.
     */
    public function isSensitive(string $table, string $field): bool
    {
        return in_array("{$table}.{$field}", self::columns(), true);
    }
}
