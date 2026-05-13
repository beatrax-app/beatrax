<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Computes and inspects the SHA-256 fingerprint of a canonical row, then
 * decides whether the incoming row is a brand-new insert, a pure
 * duplicate, or an ENRICHMENT of an existing row.
 *
 * `classify()` is the single decision point. It looks up the v3
 * fingerprint in the `transactions` table (scoped to the current user)
 * and consults the source-format rank of the incoming row vs the
 * existing one to pick a FingerprintDisposition variant:
 *
 *  - NewRowDisposition    — no existing row with this fingerprint.
 *  - DuplicateDisposition — fingerprint match but the incoming
 *                           source_ref is no stronger than the stored
 *                           one (lower rank, equal rank, or NULL).
 *  - EnrichedDisposition  — fingerprint match AND the incoming
 *                           source_ref is strictly stronger; the existing
 *                           row will be UPDATE-d with the new source_ref
 *                           and a provenance entry appended to
 *                           `enriched_from`.
 *
 * The rank function fixes the canonical source-format ordering:
 * `asn-camt053` (EndToEndId, 4) > `asn-mt940` (EREF / :61: customer ref,
 * 2) > `asn-csv` (Volgnummer, 1) > unknown (0). A NULL or empty incoming
 * source_ref scores 0 so it never beats a non-null stored one.
 *
 * The lookup explicitly filters by `user_id` (rather than relying on
 * BelongsToUser's global scope, which falls through to "no scope" in
 * unauthenticated CLI / queue / test contexts) so a fingerprint owned by
 * a different user never marks the current user's preview row as a
 * duplicate or enriches another user's row.
 */
final class FingerprintStage
{
    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly DatabaseManager $db,
    ) {}

    public function classify(CanonicalTransaction $tx, User $user): FingerprintDisposition
    {
        $fingerprint = $this->fingerprints->compose($tx);

        $existing = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first(['id', 'source_ref', 'source_format']);

        if ($existing === null) {
            return FingerprintDisposition::newRow();
        }

        $existingRef = is_string($existing->source_ref) ? $existing->source_ref : null;
        $existingFormat = is_string($existing->source_format) ? $existing->source_format : '';
        $rawId = $existing->id;
        $existingId = is_int($rawId) ? $rawId : (int) (is_numeric($rawId) ? $rawId : 0);

        $incomingRank = $this->refRank($tx->sourceRef, $tx->sourceFormat);
        $existingRank = $this->refRank($existingRef, $existingFormat);

        if ($incomingRank > $existingRank && $tx->sourceRef !== null) {
            return FingerprintDisposition::enriched(
                existingId: $existingId,
                fromSourceRef: $existingRef,
                toSourceRef: $tx->sourceRef,
            );
        }

        return FingerprintDisposition::duplicate();
    }

    /**
     * Source-format rank function.
     *
     * Returns 0 for any NULL / empty reference (so an absent ref never
     * beats a present one) and otherwise the canonical strength score
     * for the format that produced the ref.
     */
    private function refRank(?string $ref, string $format): int
    {
        if ($ref === null || $ref === '') {
            return 0;
        }

        return match ($format) {
            'asn-camt053' => 4,
            'asn-mt940' => 2,
            'asn-csv' => 1,
            default => 0,
        };
    }

    /**
     * @deprecated Use classify(). Retained for one-version transition so
     *             existing callers in the pipeline compile while the
     *             classify-based migration lands.
     */
    public function isExistingFingerprint(CanonicalTransaction $tx, User $user): bool
    {
        return $this->classify($tx, $user)->status() !== 'new';
    }
}
