<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Internal\Services\NearTotalMatch;
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
    ) {}

    public function classify(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $existing = $this->exactMatch($tx, $user);

        return $existing === null
            ? $this->nearTotalDisposition($tx, $user)
            : $this->rankedDisposition($existing, $tx, $user);
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
    private function nearTotalDisposition(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $incomingRef = $tx->sourceRef;

        // Without a reference of its own the receipt has nothing to attach and
        // nothing the ranker can weigh, which is the same reason the ledger
        // bridge leaves such a message as the sole occurrence of itself.
        if ($incomingRef === null || ! $this->ranker->isReceiptFormat($tx->sourceFormat)) {
            return FingerprintDisposition::newRow();
        }

        $near = $this->nearTotals->matching($tx, $user);

        // Never DUPLICATE, whatever the two references rank: a dropped row
        // takes its disagreement with it, and this one is only here because it
        // disagrees.
        return $near === null
            ? FingerprintDisposition::newRow()
            : FingerprintDisposition::enriched(
                existingId: self::toInt($near->id),
                fromSourceRef: self::storedRefOf($near),
                toSourceRef: $incomingRef,
                conflictingFields: $this->detectConflicts($near, $tx, $user),
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

        return FingerprintDisposition::enriched(
            existingId: self::toInt($existing->id),
            fromSourceRef: $existingRef,
            toSourceRef: $incomingRef,
            conflictingFields: $this->detectConflicts($existing, $tx, $user),
        );
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
