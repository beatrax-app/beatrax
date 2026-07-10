<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Computes and inspects the SHA-256 fingerprint of a canonical row, then
 * decides whether the incoming row is a brand-new insert, a pure
 * duplicate, or an ENRICHMENT of an existing row.
 *
 * `classify()` is the single decision point. It looks up the v3
 * fingerprint in the `transactions` table (scoped to the current user)
 * and picks a FingerprintDisposition variant:
 *
 *  - NewRowDisposition    — no existing row with this fingerprint.
 *  - DuplicateDisposition — fingerprint match where BOTH sides are
 *                           bank-statement formats (asn-csv,
 *                           camt053, mt940, ics-pdf,
 *                           paypal-csv). Same transaction re-imported
 *                           from a different statement-format export
 *                           drops as a duplicate without upgrading
 *                           source_ref. Also returned when at least one
 *                           side is a receipt format but the incoming
 *                           source_ref is no stronger than the stored
 *                           one.
 *  - EnrichedDisposition  — fingerprint match where AT LEAST ONE side
 *                           is a receipt format (paypal-receipt /
 *                           ics-receipt / google-play-receipt) AND the
 *                           incoming source_ref is strictly stronger.
 *                           The existing row is UPDATE-d with the new
 *                           source_ref and a provenance entry is
 *                           appended to `enriched_from`. The receipt-
 *                           side data (clean merchant name, line items)
 *                           is the legitimate enrichment surface;
 *                           statement-vs-statement re-imports are NOT
 *                           a legitimate enrichment site under the
 *                           policy locked here.
 *
 * Ranking is delegated to `SourceRefRanker` so the preview-time
 * classifier and the write-time enrichment applier share a single
 * canonical ordering. Receipt-format identification is delegated to
 * `SourceRefRanker::isReceiptFormat()` so the same list of receipt
 * formats drives every code path that branches on receipt-vs-statement.
 *
 * The lookup explicitly filters by `user_id` (rather than relying on
 * BelongsToUser's global scope, which falls through to "no scope" in
 * unauthenticated CLI / queue / test contexts) so a fingerprint owned by
 * a different user never marks the current user's preview row as a
 * duplicate or enriches another user's row.
 *
 * Field-conflict detection: when the disposition is ENRICHED, the
 * classifier additionally fetches the existing row's counterparty_name
 * / description / currency / amount_minor and compares them to the
 * incoming canonical row. Each field where BOTH sides have a non-null
 * value AND the values disagree (with field-appropriate normalization
 * — case-insensitive for strings, uppercase for currency, exact-int
 * for amount_minor) is recorded on the EnrichedDisposition's
 * conflictingFields map. ApplyEnrichments reads the map at write time
 * and resolves the conflict per the user's
 * receipt_conflict_resolution policy.
 */
final class FingerprintStage
{
    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly DatabaseManager $db,
        private readonly SourceRefRanker $ranker,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
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
        // upgrading source_ref. The user's UAT report (phase 16, batch
        // 7) was that re-importing the same date range as XML (CAMT.053)
        // after CSV showed every overlapping row as "enriched" — they
        // expected the second import to recognise the row as the same
        // transaction and skip it. Cross-statement-format enrichment
        // was the design intent of the rank-based source_ref upgrade,
        // but the user's policy is "same transaction → drop, never
        // enrich" for bank-statement re-imports.
        //
        // Receipt-driven enrichment is preserved: when ONE side is a
        // receipt format (paypal-receipt / ics-receipt /
        // google-play-receipt) the receipt may legitimately carry data
        // the bank statement lacks (clean merchant name, line items),
        // so the rank-based upgrade still applies. The receipt-side
        // dedup pay-off documented in
        // Modules/Receipts/tests/Contracts/FingerprintParityTest
        // depends on this branch staying alive.
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
     * Returns a map of column-name → {stored, incoming} for every
     * field where the existing row's value disagrees with the incoming
     * row's. Fields where either side is null are skipped — the
     * "conflict" semantic requires both sides to assert a value.
     *
     * Normalisation per field:
     *  - counterparty_name, description: trimmed + mb_strtolower compare
     *    (Unicode-safe; whitespace + case differences are not real
     *    conflicts at the user-visible level).
     *  - currency: uppercase compare (USD === usd is not a conflict).
     *  - amount_minor: exact int compare.
     *
     * D-07 decrypt-before-compare (14.1-12 Cluster 3 / RESEARCH Pitfall
     * 4): `counterparty_name`/`description` are ciphertext at rest for an
     * encrypted user, so the stored value is decrypted before the
     * `stringsDiffer()` compare — otherwise ciphertext can never equal
     * the incoming plaintext and every receipt-vs-statement re-import
     * would register a false-positive conflict. The DECRYPTED value is
     * what lands in `conflictingFields['stored']` so nothing downstream
     * (persisted JSON, event payload, toast UI) ever sees ciphertext.
     * `decryptValue()` is a documented no-op pass-through for a
     * non-encrypted user (or a genuinely plaintext legacy value), so
     * this call is safe unconditionally.
     *
     * @return array<string, array{stored: mixed, incoming: mixed}>
     */
    private function detectConflicts(stdClass $existing, CanonicalTransaction $tx, User $user): array
    {
        $map = [];

        $storedName = is_string($existing->counterparty_name) ? $existing->counterparty_name : null;
        if ($storedName !== null) {
            $storedName = $this->codec->decryptValue('transactions', 'counterparty_name', $storedName, $user->id, $this->session)['value'];
        }
        if ($storedName !== null && $tx->counterpartyName !== null) {
            if (self::stringsDiffer($storedName, $tx->counterpartyName)) {
                $map['counterparty_name'] = ['stored' => $storedName, 'incoming' => $tx->counterpartyName];
            }
        }

        $storedDescription = is_string($existing->description) ? $existing->description : null;
        if ($storedDescription !== null) {
            $storedDescription = $this->codec->decryptValue('transactions', 'description', $storedDescription, $user->id, $this->session)['value'];
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

    /**
     * True when two strings differ after trim + Unicode-safe casefold.
     * Used for the soft text-field conflict comparison.
     */
    private static function stringsDiffer(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) !== mb_strtolower(trim($b));
    }
}
