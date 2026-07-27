<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/import/architecture.md#applying-enrichments
 */
final class ApplyEnrichments implements AppliesEnrichments
{
    // Mirrors FingerprintStage::detectConflicts()'s four field names;
    // any other key is dropped before reaching the SQL builder so a
    // poisoned cache or producer drift can't inject an arbitrary column.
    private const ALLOWED_CONFLICT_FIELDS = ['counterparty_name', 'description', 'currency', 'amount_minor'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SourceRefRanker $ranker,
        private readonly LoggerInterface $logger,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
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

            // Re-evaluate rank against the stored ref at write time, not
            // just the preview-time snapshot — a parallel import between
            // preview and confirm may have already stored a stronger
            // reference, which this check stops from being overwritten.
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

    // The (user_id, transaction_id, field_name) UNIQUE constraint makes
    // re-import idempotent via insertOrIgnore. See
    // architecture.md#applying-enrichments for the plaintext-persistence
    // guarantee.
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

        return $this->codec->encryptAttrs('transactions', $updates, $user->id, ($this->session)());
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
