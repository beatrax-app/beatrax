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
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @link ../../../../.docs/features/import/architecture.md#applying-enrichments
 */
final class ApplyEnrichments implements AppliesEnrichments
{
    // Mirrors FingerprintStage::detectConflicts(). Anything else is dropped
    // before the SQL builder, so a poisoned cache cannot inject a column.
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

        // Method-local, never on the instance: the action is a singleton and
        // this value is per-user.
        $userChoice = $this->loadReceiptConflictChoice($user);

        $count = 0;
        foreach ($enrichments as $enrichment) {
            if ($this->applyOne($enrichment, $user, $userChoice)) {
                $count++;
            }
        }

        return $count;
    }

    private function applyOne(PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $userChoice): bool
    {
        $applied = $this->db->connection()->transaction(function () use ($enrichment, $user, $userChoice): bool {
            $row = $this->db->connection()
                ->table('transactions')
                ->where('id', $enrichment->existingTransactionId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first(['id', 'source_ref', 'source_format', 'enriched_from']);

            if ($row === null || ! $this->shouldEnrich($row, $enrichment)) {
                return false;
            }

            $this->writeEnrichment($row, $enrichment, $user, $userChoice);

            return true;
        });

        return $applied === true;
    }

    // Ranked again at write time, not just against the preview snapshot: a
    // parallel import may have stored a stronger reference in between, and
    // this is what stops it being overwritten.
    private function shouldEnrich(stdClass $row, PendingEnrichment $enrichment): bool
    {
        $existingRef = is_string($row->source_ref) ? $row->source_ref : null;
        if ($existingRef !== null && $existingRef === $enrichment->newSourceRef) {
            return false;
        }

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

        return true;
    }

    private function writeEnrichment(stdClass $row, PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $userChoice): void
    {
        $extraUpdates = $this->resolveFieldConflicts($enrichment, $user, $userChoice);

        $rawEnrichedFrom = is_string($row->enriched_from) ? $row->enriched_from : null;
        $provenance = $this->decodeEnrichedFrom($rawEnrichedFrom);
        $provenance[] = [
            'format' => $enrichment->sourceFormat,
            'ran_at' => $this->clock->now()->toIso8601String(),
            'import_run_id' => $enrichment->importRunId,
            'added' => array_merge(['source_ref'], array_keys($extraUpdates)),
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
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFieldConflicts(PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $userChoice): array
    {
        if ($enrichment->conflictingFields === []) {
            return [];
        }

        return match ($userChoice) {
            null => $this->resolveUnsetPolicy($enrichment, $user),
            ReceiptConflictChoice::PreferReceipt => $this->extractIncomingValues($enrichment, $user),
            ReceiptConflictChoice::PreferFirstWrite => [],
        };
    }

    // Only a receipt source holds its conflicts for the user to resolve;
    // every other source keeps the stored value and lets source_ref enrich
    // on its own.
    /**
     * @return array<string, mixed>
     */
    private function resolveUnsetPolicy(PendingEnrichment $enrichment, User $user): array
    {
        if ($this->ranker->isReceiptFormat($enrichment->sourceFormat)) {
            $this->holdConflicts($enrichment, $user);
        }

        return [];
    }

    // UNIQUE (user_id, transaction_id, field_name) is what makes the
    // insertOrIgnore below idempotent across re-imports.
    private function holdConflicts(PendingEnrichment $enrichment, User $user): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        foreach ($enrichment->conflictingFields as $fieldName => $values) {
            // An unknown field name here would reach an UPDATE column list
            // later, via ApplyReceiptConflictResolution.
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

    private function loadReceiptConflictChoice(User $user): ?ReceiptConflictChoice
    {
        $row = $this->db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->first(['receipt_conflict_resolution']);

        $value = $row !== null && is_string($row->receipt_conflict_resolution)
            ? $row->receipt_conflict_resolution
            : null;

        // Null, unrecognised, or the stored 'unset' sentinel all read as an
        // unanswered toast.
        return $value === null ? null : ReceiptConflictChoice::tryFrom($value);
    }

    private static function scalarToString(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default => null,
        };
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
