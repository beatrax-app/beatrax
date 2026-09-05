<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

final readonly class ApplyEnrichments implements AppliesEnrichments
{
    use CoercesScalars;

    // The columns the row is read under so the recompose has every term of the
    // tuple without a second SELECT outside the row lock.
    /** @var list<string> */
    private const array LOCKED_ROW_COLUMNS = [
        'id',
        'source_ref',
        'source_format',
        'enriched_from',
        'account_id',
        'posted_at',
        'booked_at',
        'amount_minor',
        'currency',
        'settled_amount_minor',
        'settled_currency',
        'fx_rate_used',
        'counterparty_normalized',
    ];

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SourceRefRanker $ranker,
        private LoggerInterface $logger,
        private Dispatcher $events,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private FingerprintComposer $fingerprints,
        private CounterpartyKey $counterpartyKey,
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
                ->first(self::LOCKED_ROW_COLUMNS);

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
        $plainUpdates = $this->resolveFieldConflicts($enrichment, $user, $userChoice);

        // The four amount columns and the rate are one value. Writing only the
        // native leg left every balance, budget and forecast summing the old
        // settled figure while the fingerprint was composed over the new one.
        $amount = self::resolvedAmount($row, $plainUpdates);

        // Derived from the plaintext, before encryptAttrs seals the name: the
        // counterparty key is a digest of the normalised name, and AEAD
        // ciphertext differs on every write of the same value.
        $rederived = $this->rederivedFingerprint($row, $plainUpdates, $amount, $user);
        $extraUpdates = $this->codec->encryptAttrs('transactions', $plainUpdates, $user->id, ($this->session)());

        $rawEnrichedFrom = is_string($row->enriched_from) ? $row->enriched_from : null;
        $provenance = $this->decodeEnrichedFrom($rawEnrichedFrom);
        $provenance[] = [
            'format' => $enrichment->sourceFormat,
            'ran_at' => $this->clock->now()->toIso8601String(),
            'import_run_id' => $enrichment->importRunId,
            'added' => array_merge(['source_ref'], array_keys($plainUpdates)),
        ];

        $this->db->connection()
            ->table('transactions')
            ->where('id', $enrichment->existingTransactionId)
            ->where('user_id', $user->id)
            ->update(($amount?->toColumns() ?? []) + $extraUpdates + $rederived + [
                'source_ref' => $enrichment->newSourceRef,
                'enriched_from' => json_encode($provenance, JSON_THROW_ON_ERROR),
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);
    }

    // Null where the resolution touches neither leg's amount nor its currency,
    // so a name-only conflict leaves the amount set exactly as it stands.
    /**
     * @param  array<string, mixed>  $plainUpdates
     */
    private static function resolvedAmount(stdClass $row, array $plainUpdates): ?TransactionAmount
    {
        $newAmount = $plainUpdates[EnrichmentConflictField::AmountMinor->value] ?? null;
        $newCurrency = $plainUpdates[EnrichmentConflictField::Currency->value] ?? null;

        if ($newAmount === null && $newCurrency === null) {
            return null;
        }

        $amount = new TransactionAmount(
            self::toInt($row->amount_minor),
            self::toString($row->currency),
            self::toInt($row->settled_amount_minor),
            self::toString($row->settled_currency),
            self::toStringOrNull($row->fx_rate_used),
        );

        if ($newAmount !== null) {
            $amount = $amount->withAmountMinor(self::toInt($newAmount));
        }

        return $newCurrency === null ? $amount : $amount->withCurrency(self::toString($newCurrency));
    }

    // Mirrors CounterpartyKeyBackfill::convertedTransaction(): the fingerprint
    // is composed OVER these columns, so it travels in the statement that
    // rewrites them or the row stops matching its own re-import and lands twice.
    // The dates round-trip through CarbonImmutable to reach NormalizeStage's tuple.
    /**
     * @param  array<string, mixed>  $plainUpdates
     * @return array<string, mixed>
     */
    private function rederivedFingerprint(stdClass $row, array $plainUpdates, ?TransactionAmount $amount, User $user): array
    {
        $touched = array_filter(
            $plainUpdates,
            static fn (string $field): bool => EnrichmentConflictField::tryFrom($field)?->isFingerprintInput() === true,
            ARRAY_FILTER_USE_KEY,
        );

        if ($touched === []) {
            return [];
        }

        $rederived = [];
        $normalized = self::toString($row->counterparty_normalized);

        if (array_key_exists(EnrichmentConflictField::CounterpartyName->value, $touched)) {
            $normalized = $this->counterpartyKey->forName(self::toStringOrNull($touched[EnrichmentConflictField::CounterpartyName->value]), $user->id);
            $rederived['counterparty_normalized'] = $normalized;
            $rederived['normalization_version'] = $this->fingerprints->version();
        }

        $rederived['fingerprint'] = $this->fingerprints->composeTuple(
            $user->id,
            self::toInt($row->account_id),
            CarbonImmutable::parse(self::toString($row->posted_at))->toDateString(),
            CarbonImmutable::parse(self::toString($row->booked_at))->toDateTimeString(),
            $amount->amountMinor ?? self::toInt($row->amount_minor),
            $amount->currency ?? self::toString($row->currency),
            $normalized,
        );
        $rederived['fingerprint_version'] = $this->fingerprints->version();

        return $rederived;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFieldConflicts(PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $userChoice): array
    {
        if ($enrichment->conflictingFields === []) {
            return [];
        }

        $resolution = $this->resolutionFor($enrichment, $userChoice);

        // Written down before it is settled, and under every resolution: the
        // policy decides which of the two values stands, never whether the
        // disagreement is recorded at all.
        $this->recordConflicts($enrichment, $user, $resolution);

        return $resolution === ReceiptConflictChoice::PreferReceipt
            ? self::extractIncomingValues($enrichment)
            : [];
    }

    // Null is the one outcome that reaches the reader, and what the toast asks
    // is whether to prefer RECEIPTS — a question only the receipt-incoming
    // direction poses. A statement enriching a receipt-written row settles on
    // the stored value, which is prefer_first_write's outcome under any name.
    private function resolutionFor(PendingEnrichment $enrichment, ?ReceiptConflictChoice $userChoice): ?ReceiptConflictChoice
    {
        if ($userChoice !== null) {
            return $userChoice;
        }

        return $this->ranker->isReceiptFormat($enrichment->sourceFormat)
            ? null
            : ReceiptConflictChoice::PreferFirstWrite;
    }

    // An upsert onto UNIQUE (user_id, transaction_id, field_name), not an
    // insert-or-ignore: the constraint holds one row per field, so a later
    // disagreement about that same field has to replace the one on record or
    // it becomes the dropped one. One statement, so it cannot half-apply.
    private function recordConflicts(PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $resolution): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        foreach ($enrichment->conflictingFields as $fieldName => $values) {
            // An unknown field name here would reach an UPDATE column list
            // later, via ApplyReceiptConflictResolution.
            if (EnrichmentConflictField::tryFrom((string) $fieldName) === null) {
                continue;
            }

            $stored = $values['stored'] ?? null;
            $incoming = $values['incoming'] ?? null;

            $record = [
                'stored_value' => json_encode($stored, JSON_THROW_ON_ERROR),
                'incoming_value' => json_encode($incoming, JSON_THROW_ON_ERROR),
                'incoming_source_format' => $enrichment->sourceFormat,
                'import_run_id' => $enrichment->importRunId,
                'resolution' => $resolution?->value,
                'updated_at' => $now,
            ];

            $connection->table('pending_enrichment_conflicts')->upsert(
                [$record + [
                    'user_id' => $user->id,
                    'transaction_id' => $enrichment->existingTransactionId,
                    'field_name' => $fieldName,
                    'created_at' => $now,
                ]],
                ['user_id', 'transaction_id', 'field_name'],
                array_keys($record),
            );

            $this->events->dispatch(new ReceiptConflictDetected(
                transactionId: $enrichment->existingTransactionId,
                userId: $user->id,
                field: $fieldName,
                incomingValue: self::scalarToString($incoming),
                storedValue: self::scalarToString($stored),
                importRunId: $enrichment->importRunId,
            ));
        }
    }

    // Plaintext: the caller seals these on the way into the UPDATE, after
    // rederivedFingerprint() has read the name it needs to re-key.
    /**
     * @return array<string, mixed>
     */
    private static function extractIncomingValues(PendingEnrichment $enrichment): array
    {
        $updates = [];
        foreach ($enrichment->conflictingFields as $fieldName => $values) {
            $field = EnrichmentConflictField::tryFrom((string) $fieldName);
            if ($field === null) {
                continue;
            }
            $updates[$field->value] = $values['incoming'] ?? null;
        }

        return $updates;
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
