<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Internal\Services\NearTotalMatch;
use Modules\Import\Internal\Services\RestatedRowMatch;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#8-fingerprint-fingerprintstage
 */
final readonly class FingerprintStage
{
    use CoercesScalars;

    public function __construct(
        private FingerprintComposer $fingerprints,
        private DatabaseManager $db,
        private SourceRefRanker $ranker,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private NearTotalMatch $nearTotals,
        private RestatedRowMatch $restatements,
    ) {}

    public function classify(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $existing = $this->exactMatch($tx, $user);

        return $existing === null
            ? $this->unmatchedDisposition($tx, $user)
            : $this->rankedDisposition($existing, $tx, $user);
    }

    // A reference the ledger already holds is an exact answer, so it is asked
    // before the receipt band's approximate one. Where both fire they point at
    // one row, and the reference is the term the bank itself assigned.
    private function unmatchedDisposition(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $reference = $tx->sourceRef;

        // Both arms below need a reference of the row's own: one to find the
        // stored row by, the other to weigh against the stored one. Without it
        // the ledger bridge leaves such a row as the sole occurrence of itself.
        if ($reference === null) {
            return FingerprintDisposition::newRow();
        }

        $restated = $this->restatements->matching($tx, $user);

        return $restated === null
            ? $this->nearTotalDisposition($tx, $reference, $user)
            : $this->enrichedAgainst($restated, $tx, $reference, $user);
    }

    private function exactMatch(CanonicalTransaction $tx, User $user): ?stdClass
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('fingerprint', $this->fingerprints->compose($tx))
            ->first(NearTotalMatch::CONFLICT_COLUMNS);
    }

    // A receipt whose total sits inside the band is describing the row it
    // nearly matches, so the two are one event and the difference a
    // disagreement to record. Only an incoming receipt gets the band: a
    // statement is the authority on what settled, and no fuzzy total absorbs one.
    private function nearTotalDisposition(CanonicalTransaction $tx, string $incomingRef, User $user): FingerprintDisposition
    {
        if (! $this->ranker->isReceiptFormat($tx->sourceFormat)) {
            return FingerprintDisposition::newRow();
        }

        $near = $this->nearTotals->matching($tx, $user);

        // Never DUPLICATE, whatever the two references rank: a dropped row
        // takes its disagreement with it, and this one is only here because it
        // disagrees.
        return $near === null
            ? FingerprintDisposition::newRow()
            : $this->enrichedAgainst($near, $tx, $incomingRef, $user);
    }

    // One construction for all three arms, so the near-total and restatement
    // lookups cannot hand the applier a disposition shaped unlike the exact
    // arm's.
    private function enrichedAgainst(stdClass $existing, CanonicalTransaction $tx, string $incomingRef, User $user): EnrichedDisposition
    {
        return FingerprintDisposition::enriched(
            existingId: self::toInt($existing->id),
            fromSourceRef: self::storedRefOf($existing),
            toSourceRef: $incomingRef,
            conflictingFields: $this->detectConflicts($existing, $tx, $user),
        );
    }

    private static function storedRefOf(stdClass $row): ?string
    {
        return is_string($row->source_ref) ? $row->source_ref : null;
    }

    private function rankedDisposition(stdClass $existing, CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $existingFormat = is_string($existing->source_format) ? $existing->source_format : '';
        $existingRef = self::storedRefOf($existing);
        $incomingRef = $tx->sourceRef;

        // Two statements colliding drop as duplicates with no source_ref
        // upgrade. Enrichment needs a receipt on one side and an incoming
        // ref that both exists and outranks the stored one.
        $oneSideReceipt = $this->ranker->isReceiptFormat($tx->sourceFormat)
            || $this->ranker->isReceiptFormat($existingFormat);

        if ($incomingRef === null || ! $oneSideReceipt
            || $this->ranker->rank($incomingRef, $tx->sourceFormat) <= $this->ranker->rank($existingRef, $existingFormat)) {
            return FingerprintDisposition::duplicate();
        }

        return $this->enrichedAgainst($existing, $tx, $incomingRef, $user);
    }

    /**
     * @link ../../../../../.docs/architecture/ingestion-pipeline.md#8-fingerprint-fingerprintstage
     *
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private function detectConflicts(stdClass $existing, CanonicalTransaction $tx, User $user): array
    {
        return array_merge(
            $this->encryptedTextConflict(EnrichmentConflictField::CounterpartyName, $existing->counterparty_name, $tx->counterpartyName, $user),
            $this->encryptedTextConflict(EnrichmentConflictField::Description, $existing->description, $tx->description, $user),
            self::currencyConflict($existing->currency, $tx->currency),
            self::amountConflict($existing->amount_minor, $tx->amountMinor),
        );
    }

    // Compared as plaintext: re-encrypting the same value yields different
    // ciphertext, which would read as a conflict.
    /**
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private function encryptedTextConflict(EnrichmentConflictField $field, mixed $rawStored, ?string $incoming, User $user): array
    {
        $stored = is_string($rawStored) ? $rawStored : null;
        if ($stored !== null) {
            $stored = $this->codec->decryptValue('transactions', $field->value, $stored, $user->id, ($this->session)())['value'];
        }

        if ($stored === null || $incoming === null || ! self::stringsDiffer($stored, $incoming)) {
            return [];
        }

        return [$field->value => ['stored' => $stored, 'incoming' => $incoming]];
    }

    /**
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private static function currencyConflict(mixed $rawStored, string $incoming): array
    {
        $stored = is_string($rawStored) ? $rawStored : null;
        if ($stored === null || $incoming === '' || mb_strtoupper($stored) === mb_strtoupper($incoming)) {
            return [];
        }

        return [EnrichmentConflictField::Currency->value => ['stored' => $stored, 'incoming' => $incoming]];
    }

    /**
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private static function amountConflict(mixed $rawStored, int $incoming): array
    {
        $stored = is_numeric($rawStored) ? (int) $rawStored : null;
        if ($stored === null || $stored === $incoming) {
            return [];
        }

        return [EnrichmentConflictField::AmountMinor->value => ['stored' => $stored, 'incoming' => $incoming]];
    }

    private static function stringsDiffer(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) !== mb_strtolower(trim($b));
    }
}
