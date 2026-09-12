<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Dto\TransactionBooking;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Support\SearchedColumns;
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
        'occurrence_ordinal',
        'status',
    ];

    // The two conflict fields the fingerprint hashes as themselves, which is
    // what makes a disagreement about either a disagreement about identity.
    /** @var array<string, true> */
    private const array TOTAL_FIELDS = [
        EnrichmentConflictField::AmountMinor->value => true,
        EnrichmentConflictField::Currency->value => true,
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
        private SearchIndexWriterContract $searchIndex,
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

            // Order matters and short-circuits: a row that is not here cannot
            // be read for a status, and a reconciled row is refused before its
            // reference is ranked, because the ranking is about which file wins
            // and this is about the reader's own assertion outranking both.
            if ($row === null || $this->lockedByAReconcile($row, $enrichment) || ! $this->shouldEnrich($row, $enrichment)) {
                return false;
            }

            return $this->writeEnrichment($row, $enrichment, $user, $userChoice);
        });

        return $applied === true;
    }

    // A reconcile is the reader's own assertion that this row and a statement
    // agree, so a later file carrying a stronger reference must not rewrite the
    // figure they checked. The receipt sibling of this write already refuses;
    // this one adopted the amount instead.
    private function lockedByAReconcile(stdClass $row, PendingEnrichment $enrichment): bool
    {
        if (! TransactionStatusQuery::locksEdits($row->status)) {
            return false;
        }

        $this->logger->debug('Skipping enrichment: the transaction is reconciled', [
            'transaction_id' => $enrichment->existingTransactionId,
            'incoming_format' => $enrichment->sourceFormat,
        ]);

        return true;
    }

    // Ranked again at write time: a parallel import may have stored a stronger
    // reference since the preview, and this is what stops it being overwritten.
    // A receipt whose total disagrees is admitted past the ranking anyway — its
    // row is written nowhere else, so declining it discards the disagreement.
    private function shouldEnrich(stdClass $row, PendingEnrichment $enrichment): bool
    {
        $existingFormat = is_string($row->source_format) ? $row->source_format : '';
        $existingRank = $this->ranker->rank(self::storedRef($row), $existingFormat);
        $incomingRank = $this->ranker->rank($enrichment->newSourceRef, $enrichment->sourceFormat);

        if (self::strengthens($row, $enrichment, $existingRank, $incomingRank) || self::disagreesAboutTheTotal($enrichment)) {
            return true;
        }

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

    private static function strengthens(stdClass $row, PendingEnrichment $enrichment, int $existingRank, int $incomingRank): bool
    {
        return self::storedRef($row) !== $enrichment->newSourceRef && $incomingRank > $existingRank;
    }

    // amount_minor and currency are hashed into the fingerprint as themselves,
    // so the exact lookup can only disagree about either on a row whose digest
    // no longer describes it. counterparty_name is out: it reaches the tuple
    // through a key two spellings share, so re-imports would re-open forever.
    private static function disagreesAboutTheTotal(PendingEnrichment $enrichment): bool
    {
        return array_intersect_key(self::TOTAL_FIELDS, $enrichment->conflictingFields) !== [];
    }

    // Null where the stored reference stands. An enrichment admitted for its
    // disagreement alone carries one that does not outrank what is there, and
    // writing it would trade a bank's end-to-end reference for a receipt's.
    private function strongerRef(stdClass $row, PendingEnrichment $enrichment): ?string
    {
        $existingFormat = is_string($row->source_format) ? $row->source_format : '';
        $strengthens = self::strengthens(
            $row,
            $enrichment,
            $this->ranker->rank(self::storedRef($row), $existingFormat),
            $this->ranker->rank($enrichment->newSourceRef, $enrichment->sourceFormat),
        );

        return $strengthens ? $enrichment->newSourceRef : null;
    }

    private static function storedRef(stdClass $row): ?string
    {
        return is_string($row->source_ref) ? $row->source_ref : null;
    }

    private function writeEnrichment(stdClass $row, PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $userChoice): bool
    {
        $plainUpdates = $this->resolveFieldConflicts($row, $enrichment, $user, $userChoice);

        // The four amount columns and the rate are one value. Writing only the
        // native leg left every balance, budget and forecast summing the old
        // settled figure while the fingerprint was composed over the new one.
        $amount = self::resolvedAmount($row, $plainUpdates);

        // Which day the source filed the transaction on is not a disagreement
        // two records have; it is what the later record says, and a reader
        // handed two dates has no ground to choose between them. Taken whole
        // or the row keeps a day its own restatement no longer matches.
        $booking = $enrichment->restates;

        // Derived from the plaintext, before encryptAttrs seals the name: the
        // counterparty key is a digest of the normalised name, and AEAD
        // ciphertext differs on every write of the same value.
        $rederived = $this->rederivedFingerprint($row, $plainUpdates, $amount, $booking, $user);
        $extraUpdates = $this->codec->encryptAttrs('transactions', $plainUpdates, $user->id, ($this->session)());

        $sourceRef = $this->strongerRef($row, $enrichment);

        $rawEnrichedFrom = is_string($row->enriched_from) ? $row->enriched_from : null;
        $provenance = $this->decodeEnrichedFrom($rawEnrichedFrom);
        $provenance[] = [
            'format' => $enrichment->sourceFormat,
            'ran_at' => $this->clock->now()->toIso8601String(),
            'import_run_id' => $enrichment->importRunId,
            'added' => array_merge(
                $sourceRef === null ? [] : ['source_ref'],
                $booking === null ? [] : array_keys($booking->toColumns()),
                array_keys($plainUpdates),
            ),
        ];

        try {
            $this->db->connection()
                ->table('transactions')
                ->where('id', $enrichment->existingTransactionId)
                ->where('user_id', $user->id)
                ->update(($amount?->toColumns() ?? []) + ($booking?->toColumns() ?? []) + $extraUpdates + $rederived + ($sourceRef === null ? [] : ['source_ref' => $sourceRef]) + [
                    'enriched_from' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        } catch (UniqueConstraintViolationException) {
            // Moved onto a row the ledger already holds, which is an answer and
            // not a failure: the restating row describes that transaction. The
            // stored row stands and the confirm carries on, where letting this
            // out would roll the whole import's enrichment phase back.
            $this->logger->warning('Enrichment moved a row onto one the ledger already holds; the stored row stands', [
                'transaction_id' => $enrichment->existingTransactionId,
                'incoming_format' => $enrichment->sourceFormat,
            ]);

            return false;
        }

        // Inside the same transaction as the UPDATE, so a rollback takes the
        // document with it. Without this the receipt renamed the row and the
        // index went on answering to the bank's narrative, so the reader could
        // not find by the merchant name the enrichment had just given them.
        if (SearchedColumns::touchedBy(SearchedColumns::TRANSACTIONS, array_keys($plainUpdates))) {
            $this->searchIndex->upsertForTransaction($enrichment->existingTransactionId, $user->id);
        }

        return true;
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
    private function rederivedFingerprint(stdClass $row, array $plainUpdates, ?TransactionAmount $amount, ?TransactionBooking $booking, User $user): array
    {
        $touched = array_filter(
            $plainUpdates,
            static fn (string $field): bool => EnrichmentConflictField::tryFrom($field)?->isFingerprintInput() === true,
            ARRAY_FILTER_USE_KEY,
        );

        // An adopted booking moves three terms of the tuple on its own, so a
        // restatement recomposes even where the reader's policy left every
        // field alone.
        if ($touched === [] && $booking === null) {
            return [];
        }

        $rederived = [];
        $normalized = self::toString($row->counterparty_normalized);

        if (array_key_exists(EnrichmentConflictField::CounterpartyName->value, $touched)) {
            $normalized = $this->counterpartyKey->forName(self::toStringOrNull($touched[EnrichmentConflictField::CounterpartyName->value]), $user->id);
            $rederived['counterparty_normalized'] = $normalized;
            $rederived['normalization_version'] = $this->fingerprints->version();
        }

        // Read off the booking where one was adopted and off the stored row
        // otherwise: composing over columns the same statement is rewriting
        // would store a digest that stops describing the row it sits on.
        $rederived['fingerprint'] = $this->fingerprints->composeTuple(new FingerprintTuple(
            $user->id,
            self::toInt($row->account_id),
            $booking->postedAt ?? CarbonImmutable::parse(self::toString($row->posted_at))->toDateString(),
            $booking->bookedAt ?? CarbonImmutable::parse(self::toString($row->booked_at))->toDateTimeString(),
            $amount->amountMinor ?? self::toInt($row->amount_minor),
            $amount->currency ?? self::toString($row->currency),
            $normalized,
            $booking->occurrenceOrdinal ?? self::toInt($row->occurrence_ordinal),
        ));
        $rederived['fingerprint_version'] = $this->fingerprints->version();

        return $rederived;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFieldConflicts(stdClass $row, PendingEnrichment $enrichment, User $user, ?ReceiptConflictChoice $userChoice): array
    {
        if ($enrichment->conflictingFields === []) {
            return [];
        }

        $resolution = $this->resolutionFor($row, $enrichment, $userChoice);

        // Written down before it is settled, and under every resolution: the
        // policy decides which of the two values stands, never whether the
        // disagreement is recorded at all.
        $this->recordConflicts($enrichment, $user, $resolution);

        return $resolution === ReceiptConflictChoice::PreferReceipt
            ? self::extractIncomingValues($enrichment)
            : [];
    }

    // Null is the one outcome that reaches the reader. A row restating one the
    // ledger already holds under the same reference poses that question as
    // squarely as a receipt does: its figure is written nowhere else, so
    // settling it unasked is discarding it.
    private function resolutionFor(stdClass $row, PendingEnrichment $enrichment, ?ReceiptConflictChoice $userChoice): ?ReceiptConflictChoice
    {
        if ($userChoice !== null) {
            return $userChoice;
        }

        $restatesStoredRow = self::storedRef($row) === $enrichment->newSourceRef;

        // Any other statement enriching a receipt-written row settles on the
        // stored value, which is prefer_first_write's outcome under any name.
        return $this->ranker->isReceiptFormat($enrichment->sourceFormat) || $restatesStoredRow
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
