<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#8-fingerprint-fingerprintstage
 */
final class FingerprintStage
{
    use CoercesScalars;

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly DatabaseManager $db,
        private readonly SourceRefRanker $ranker,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    public function classify(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $fingerprint = $this->fingerprints->compose($tx);

        $existing = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first([
                'id',
                'source_ref',
                'source_format',
                'counterparty_name',
                'description',
                'currency',
                'amount_minor',
            ]);

        if ($existing === null) {
            return FingerprintDisposition::newRow();
        }

        $existingFormat = is_string($existing->source_format) ? $existing->source_format : '';
        $existingRef = is_string($existing->source_ref) ? $existing->source_ref : null;
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
            $this->encryptedTextConflict('counterparty_name', $existing->counterparty_name, $tx->counterpartyName, $user),
            $this->encryptedTextConflict('description', $existing->description, $tx->description, $user),
            self::currencyConflict($existing->currency, $tx->currency),
            self::amountConflict($existing->amount_minor, $tx->amountMinor),
        );
    }

    // Compared as plaintext: re-encrypting the same value yields different
    // ciphertext, which would read as a conflict.
    /**
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private function encryptedTextConflict(string $column, mixed $rawStored, ?string $incoming, User $user): array
    {
        $stored = is_string($rawStored) ? $rawStored : null;
        if ($stored !== null) {
            $stored = $this->codec->decryptValue('transactions', $column, $stored, $user->id, ($this->session)())['value'];
        }

        if ($stored === null || $incoming === null || ! self::stringsDiffer($stored, $incoming)) {
            return [];
        }

        return [$column => ['stored' => $stored, 'incoming' => $incoming]];
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

        return ['currency' => ['stored' => $stored, 'incoming' => $incoming]];
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

        return ['amount_minor' => ['stored' => $stored, 'incoming' => $incoming]];
    }

    private static function stringsDiffer(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) !== mb_strtolower(trim($b));
    }
}
