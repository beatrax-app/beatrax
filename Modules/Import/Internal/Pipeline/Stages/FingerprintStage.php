<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
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

        // Statement-vs-statement collisions drop as duplicates without
        // upgrading source_ref — the policy is "same transaction, never
        // enrich" for bank-statement re-imports. Receipt-driven
        // enrichment (one side a receipt format) still applies.
        $incomingIsReceipt = $this->ranker->isReceiptFormat($tx->sourceFormat);
        $existingIsReceipt = $this->ranker->isReceiptFormat($existingFormat);
        if (! $incomingIsReceipt && ! $existingIsReceipt) {
            return FingerprintDisposition::duplicate();
        }

        $existingRef = is_string($existing->source_ref) ? $existing->source_ref : null;
        $rawId = $existing->id;
        $existingId = is_int($rawId) ? $rawId : (int) (is_numeric($rawId) ? $rawId : 0);

        $incomingRank = $this->ranker->rank($tx->sourceRef, $tx->sourceFormat);
        $existingRank = $this->ranker->rank($existingRef, $existingFormat);

        if ($incomingRank > $existingRank && $tx->sourceRef !== null) {
            $conflictingFields = $this->detectConflicts($existing, $tx, $user);

            return FingerprintDisposition::enriched(
                existingId: $existingId,
                fromSourceRef: $existingRef,
                toSourceRef: $tx->sourceRef,
                conflictingFields: $conflictingFields,
            );
        }

        return FingerprintDisposition::duplicate();
    }

    /**
     * @link ../../../../../.docs/architecture/ingestion-pipeline.md#8-fingerprint-fingerprintstage
     *
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private function detectConflicts(stdClass $existing, CanonicalTransaction $tx, User $user): array
    {
        $map = [];

        $storedName = is_string($existing->counterparty_name) ? $existing->counterparty_name : null;
        if ($storedName !== null) {
            $storedName = $this->codec->decryptValue('transactions', 'counterparty_name', $storedName, $user->id, ($this->session)())['value'];
        }
        if ($storedName !== null && $tx->counterpartyName !== null) {
            if (self::stringsDiffer($storedName, $tx->counterpartyName)) {
                $map['counterparty_name'] = ['stored' => $storedName, 'incoming' => $tx->counterpartyName];
            }
        }

        $storedDescription = is_string($existing->description) ? $existing->description : null;
        if ($storedDescription !== null) {
            $storedDescription = $this->codec->decryptValue('transactions', 'description', $storedDescription, $user->id, ($this->session)())['value'];
        }
        if ($storedDescription !== null && $tx->description !== null) {
            if (self::stringsDiffer($storedDescription, $tx->description)) {
                $map['description'] = ['stored' => $storedDescription, 'incoming' => $tx->description];
            }
        }

        $storedCurrency = is_string($existing->currency) ? $existing->currency : null;
        if ($storedCurrency !== null && $tx->currency !== '') {
            if (mb_strtoupper($storedCurrency) !== mb_strtoupper($tx->currency)) {
                $map['currency'] = ['stored' => $storedCurrency, 'incoming' => $tx->currency];
            }
        }

        $storedAmount = is_numeric($existing->amount_minor) ? (int) $existing->amount_minor : null;
        if ($storedAmount !== null && $storedAmount !== $tx->amountMinor) {
            $map['amount_minor'] = ['stored' => $storedAmount, 'incoming' => $tx->amountMinor];
        }

        return $map;
    }

    private static function stringsDiffer(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) !== mb_strtolower(trim($b));
    }
}
