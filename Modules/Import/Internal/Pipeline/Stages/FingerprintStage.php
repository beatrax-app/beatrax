<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Import\Public\Services\SourceRefRanker;
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
 * Ranking is delegated to `SourceRefRanker` so the preview-time
 * classifier and the write-time enrichment applier share a single
 * canonical ordering (`asn-camt053` > `asn-mt940` > `asn-csv` > unknown,
 * with NULL or empty refs ranked at zero).
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
        private readonly SourceRefRanker $ranker,
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

        $incomingRank = $this->ranker->rank($tx->sourceRef, $tx->sourceFormat);
        $existingRank = $this->ranker->rank($existingRef, $existingFormat);

        if ($incomingRank > $existingRank && $tx->sourceRef !== null) {
            return FingerprintDisposition::enriched(
                existingId: $existingId,
                fromSourceRef: $existingRef,
                toSourceRef: $tx->sourceRef,
            );
        }

        return FingerprintDisposition::duplicate();
    }
}
