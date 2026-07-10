<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

/**
 * Applies pending cross-format enrichments produced by the import
 * pipeline. Each enrichment is wrapped in a per-row DB transaction with
 * `lockForUpdate()` so two concurrent imports targeting the same row's
 * source_ref either serialise or short-circuit on a rank re-evaluation
 * rather than racing on the UPDATE.
 *
 * Rank re-evaluation at write time:
 *  - The stored source_ref is read again inside the locked transaction
 *    and re-ranked against the incoming PendingEnrichment via
 *    `SourceRefRanker`. If the stored ref now outranks (or ties) the
 *    incoming ref, the enrichment is dropped as a no-op so a cached
 *    weaker reference can never overwrite a freshly-stored stronger one.
 *
 * The enriched_from JSON column accumulates a full append-only
 * provenance trail. Every successful application appends one entry of
 * the shape:
 *
 *     ['format' => string, 'ran_at' => string, 'import_run_id' => int, 'added' => list<string>]
 *
 * The row's source_ref is overwritten with the stronger incoming
 * reference. Caller scoping by user is enforced inside the UPDATE so a
 * forged PendingEnrichment whose existingTransactionId belongs to
 * another user resolves zero rows and is silently dropped.
 *
 * Receipt-conflict branch: when PendingEnrichment.conflictingFields
 * is non-empty AND the source format is a receipt format AND the
 * user's receipt_conflict_resolution is still `unset`, the action
 * INSERTs a row into pending_enrichment_conflicts for each
 * conflicting field and dispatches ReceiptConflictDetected (the
 * toast surfaces the choice). When the policy is `prefer_receipt`
 * the incoming values land silently in the same UPDATE; when
 * `prefer_first_write` the stored values are kept and only the
 * source_ref enrichment proceeds.
 *
 * Cross-user / cross-instance safety: the user policy is read into
 * a method-local variable per __invoke() call — never cached on the
 * action instance. The action is bound as a singleton in the
 * service provider; an instance-level cache would leak across users
 * on the same queue-worker process, violating the cross-user
 * isolation invariant.
 */
final class ApplyEnrichments implements AppliesEnrichments
{
    /**
     * Whitelist of `conflictingFields` keys that may flow through as
     * literal SQL column names on the `transactions` UPDATE. Mirrors
     * the four field names emitted by `FingerprintStage::detectConflicts`.
     * Any other key is dropped silently before reaching the SQL
     * builder so a poisoned preview cache or a future producer drift
     * cannot turn an array key into an arbitrary column name.
     */
    private const ALLOWED_CONFLICT_FIELDS = ['counterparty_name', 'description', 'currency', 'amount_minor'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SourceRefRanker $ranker,
        private readonly LoggerInterface $logger,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function __invoke(array $enrichments, User $user): int
    {
        if ($enrichments === []) {
            return 0;
        }

        // Method-local cache of the user's receipt-conflict policy.
        // Single indexed SELECT on users.id; the per-call cost is
        // negligible at single-user scale and stays correct at multi-
        // user scale because we never store this on the action instance.
        $userPolicy = $this->loadReceiptConflictPolicy($user);

        $count = 0;
        foreach ($enrichments as $enrichment) {
            if ($this->applyOne($enrichment, $user, $userPolicy)) {
                $count++;
            }
        }

        return $count;
    }

    private function applyOne(PendingEnrichment $enrichment, User $user, string $userPolicy): bool
    {
        $applied = $this->db->connection()->transaction(function () use ($enrichment, $user, $userPolicy): bool {
            $row = $this->db->connection()
                ->table('transactions')
                ->where('id', $enrichment->existingTransactionId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first(['id', 'source_ref', 'source_format', 'enriched_from']);

            if ($row === null) {
                return false;
            }

            $existingRef = is_string($row->source_ref) ? $row->source_ref : null;
            if ($existingRef !== null && $existingRef === $enrichment->newSourceRef) {
                return false;
            }

            // Re-evaluate the rank ordering against the stored ref at
            // write time, not just the preview-time snapshot. Between
            // preview and confirm, a parallel import (or a re-preview)
            // may already have stored a stronger reference; without this
            // check the cached PendingEnrichment would silently
            // overwrite the stronger value with a weaker one.
            $existingFormat = is_string($row->source_format) ? $row->source_format : '';
            $incomingRank = $this->ranker->rank($enrichment->newSourceRef, $enrichment->sourceFormat);
            $existingRank = $this->ranker->rank($existingRef, $existingFormat);

            if ($incomingRank <= $existingRank) {
                $this->logger->debug(
                    'Skipping enrichment: stored source_ref is already at least as strong',
                    [
                        'transaction_id' => $enrichment->existingTransactionId,
                        'existing_format' => $existingFormat,
                        'existing_rank' => $existingRank,
                        'incoming_format' => $enrichment->sourceFormat,
                        'incoming_rank' => $incomingRank,
                    ],
                );

                return false;
            }

            $extraUpdates = $this->resolveFieldConflicts($enrichment, $user, $userPolicy);

            $rawEnrichedFrom = is_string($row->enriched_from) ? $row->enriched_from : null;
            $provenance = $this->decodeEnrichedFrom($rawEnrichedFrom);
            $added = ['source_ref'];
            foreach (array_keys($extraUpdates) as $columnName) {
                $added[] = $columnName;
            }
            $provenance[] = [
                'format' => $enrichment->sourceFormat,
                'ran_at' => $this->clock->now()->toIso8601String(),
                'import_run_id' => $enrichment->importRunId,
                'added' => $added,
            ];

            $this->db->connection()
                ->table('transactions')
                ->where('id', $enrichment->existingTransactionId)
                ->where('user_id', $user->id)
                ->update($extraUpdates + [
                    'source_ref' => $enrichment->newSourceRef,
                    'enriched_from' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);

            return true;
        });

        return $applied === true;
    }

    /**
     * Returns the column-name → value map of per-field updates that
     * should land in the same UPDATE statement as the source_ref
     * change. For the `unset` policy on a receipt-format enrichment
     * with non-empty conflictingFields, this method:
     *  - INSERTs one pending_enrichment_conflicts row per field
     *  - dispatches ReceiptConflictDetected per field
     *  - SKIPS the per-field write (returns no per-field updates)
     *
     * For `prefer_receipt`: returns the incoming values keyed by
     * column name so the caller's UPDATE applies them silently.
     * For `prefer_first_write`: returns an empty map so the stored
     * value is preserved.
     *
     * For non-receipt formats OR an empty conflictingFields map:
     * returns an empty map — the pre-Wave-3 behaviour (pure
     * source_ref enrichment) is preserved.
     *
     * @return array<string, mixed>
     */
    private function resolveFieldConflicts(PendingEnrichment $enrichment, User $user, string $userPolicy): array
    {
        if ($enrichment->conflictingFields === []) {
            return [];
        }

        $isReceipt = $this->ranker->isReceiptFormat($enrichment->sourceFormat);

        if ($userPolicy === 'unset') {
            if (! $isReceipt) {
                // Non-receipt source — the conflict toast flow is the
                // receipt-vs-CSV mitigation only. For other paths, keep
                // the stored value silently (the safer default) and
                // leave source_ref to be enriched.
                return [];
            }
            $this->holdConflicts($enrichment, $user);

            return [];
        }

        if ($userPolicy === 'prefer_receipt') {
            return $this->extractIncomingValues($enrichment, $user);
        }

        // prefer_first_write: keep the stored value verbatim — no
        // per-field updates land.
        return [];
    }

    /**
     * INSERTs one pending_enrichment_conflicts row per conflicting
     * field + dispatches one ReceiptConflictDetected event per field.
     * The (user_id, transaction_id, field_name) UNIQUE constraint on
     * the table makes re-import idempotent — the second import upserts
     * via insertOrIgnore so no duplicate pending rows accumulate.
     *
     * D-07 plaintext-only persistence (14.1-12 Cluster 3): `$values['stored']`
     * arrives here ALREADY DECRYPTED — `FingerprintStage::detectConflicts()`
     * is the sole producer of `conflictingFields` and decrypts the stored
     * `counterparty_name`/`description` before ever populating the map (see
     * that method's own D-07 fix). So `stored_value` and the dispatched
     * event's `csvValue` below carry plaintext, never ciphertext, without
     * any further decrypt step here.
     */
    private function holdConflicts(PendingEnrichment $enrichment, User $user): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        foreach ($enrichment->conflictingFields as $fieldName => $values) {
            // Defence-in-depth: skip unknown field names so a poisoned
            // preview cache cannot persist arbitrary `field_name` values
            // that would later flow into an UPDATE column list via
            // ApplyReceiptConflictResolution.
            if (! in_array($fieldName, self::ALLOWED_CONFLICT_FIELDS, true)) {
                continue;
            }

            $stored = $values['stored'] ?? null;
            $incoming = $values['incoming'] ?? null;

            $connection->table('pending_enrichment_conflicts')->insertOrIgnore([
                'user_id' => $user->id,
                'transaction_id' => $enrichment->existingTransactionId,
                'field_name' => $fieldName,
                'stored_value' => json_encode($stored, JSON_THROW_ON_ERROR),
                'incoming_value' => json_encode($incoming, JSON_THROW_ON_ERROR),
                'incoming_source_format' => $enrichment->sourceFormat,
                'import_run_id' => $enrichment->importRunId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->events->dispatch(new ReceiptConflictDetected(
                transactionId: $enrichment->existingTransactionId,
                userId: $user->id,
                field: $fieldName,
                receiptValue: self::scalarToString($incoming),
                csvValue: self::scalarToString($stored),
                importRunId: $enrichment->importRunId,
            ));
        }
    }

    /**
     * Build the per-field update map for the prefer_receipt policy:
     * the incoming value lands on each conflicting column. Field names
     * outside `ALLOWED_CONFLICT_FIELDS` are dropped so a corrupted
     * cache row cannot inject an unintended column name into the
     * UPDATE.
     *
     * D-07 encrypt-incoming-before-update (14.1-12 Cluster 3 / CR-01/
     * CR-02 class): the incoming value is a fresh plaintext string
     * (parsed straight off the receipt), so it must be encrypted
     * before it lands in the `transactions` UPDATE for an encrypted
     * user — otherwise plaintext is re-introduced into an at-rest-
     * encrypted column. `encryptAttrs()` only touches string-valued,
     * `SensitiveFieldRegistry`-listed columns (counterparty_name/
     * description), so `currency`/`amount_minor` pass through
     * untouched, and it is a documented no-op pass-through for a
     * non-encrypted user.
     *
     * @return array<string, mixed>
     */
    private function extractIncomingValues(PendingEnrichment $enrichment, User $user): array
    {
        $updates = [];
        foreach ($enrichment->conflictingFields as $fieldName => $values) {
            if (! in_array($fieldName, self::ALLOWED_CONFLICT_FIELDS, true)) {
                continue;
            }
            $updates[$fieldName] = $values['incoming'] ?? null;
        }

        return $this->codec->encryptAttrs('transactions', $updates, $user->id, $this->session);
    }

    private function loadReceiptConflictPolicy(User $user): string
    {
        $row = $this->db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->first(['receipt_conflict_resolution']);

        if ($row === null) {
            return 'unset';
        }

        $value = is_string($row->receipt_conflict_resolution) ? $row->receipt_conflict_resolution : 'unset';
        if (! in_array($value, ['unset', 'prefer_receipt', 'prefer_first_write'], true)) {
            return 'unset';
        }

        return $value;
    }

    private static function scalarToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return list<array{format: string, ran_at: string, import_run_id: int, added: list<string>}>
     */
    private function decodeEnrichedFrom(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return [];
        }

        /** @var list<array{format: string, ran_at: string, import_run_id: int, added: list<string>}> $entries */
        $entries = array_values($decoded);

        return $entries;
    }
}
