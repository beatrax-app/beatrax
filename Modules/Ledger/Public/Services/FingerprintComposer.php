<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Normalizer;

/**
 * Produces the canonical SHA-256 fingerprint of a CanonicalTransaction. The
 * fingerprint is the second-layer idempotency guard — the composite UNIQUE
 * index on `transactions(user_id, account_id, posted_at, booked_at,
 * amount_minor, currency, counterparty_normalized)` is the first.
 *
 * The tuple is prefixed with `user_id` so the same row imported under two
 * different users hashes to two different fingerprints. Without that prefix
 * the SHA-256 UNIQUE index would silently reject the second user's row as a
 * "duplicate" of the first user's row.
 *
 * `normalize()` collapses a raw counterparty name into a stable string used
 * inside the fingerprint tuple. NORMALIZATION_VERSION is bumped whenever the
 * algorithm or the tuple shape changes so the column on `transactions`
 * (`normalization_version`) lets old rows be re-normalised against a new
 * algorithm without invalidating historic fingerprints.
 */
final class FingerprintComposer
{
    /**
     * Version stamp persisted on every transaction row as
     * `normalization_version`. The current algorithm hashes the tuple
     * `user_id | account_id | posted_at | booked_at | amount_minor |
     * currency | counterparty_normalized` combined with the
     * counterparty-normalisation rules implemented in `normalize()`:
     * lowercased, NFD-stripped of combining marks, non-alphanumeric runs
     * collapsed to single spaces, whitespace-collapsed, trim-and-truncated
     * to 80 UTF-8 characters. `booked_at` carries second-resolution so
     * two same-day same-merchant same-amount entries posted seconds apart
     * never collide. `source_ref` is intentionally absent: the same
     * real-world transaction surfaces in CSV and CAMT.053 exports with
     * different reference values, and the fingerprint must equate those.
     *
     * Bump the constant whenever either the tuple shape or the
     * `normalize()` output changes; a stored row with a lower version
     * stamp signals "re-derive the fingerprint before comparing against
     * the current algorithm". Re-derive existing rows via the
     * `diederik:rederive-fingerprints` artisan command when bumping past
     * this version.
     */
    public const NORMALIZATION_VERSION = 3;

    public function compose(CanonicalTransaction $tx): string
    {
        $tuple = implode('|', [
            (string) ($tx->userId ?? 0),
            (string) $tx->accountId,
            $tx->postedAt->toDateString(),
            $tx->bookedAt->toDateTimeString(),
            (string) $tx->amountMinor,
            $tx->currency,
            $tx->counterpartyNormalized,
        ]);

        return hash('sha256', $tuple);
    }

    public function normalize(string $rawName): string
    {
        $s = mb_strtolower($rawName, 'UTF-8');
        $s = $this->stripDiacritics($s);
        $s = preg_replace('/[^\p{L}\p{N}& ]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return mb_substr(trim($s), 0, 80, 'UTF-8');
    }

    public function version(): int
    {
        return self::NORMALIZATION_VERSION;
    }

    /**
     * Strips combining marks (accents, umlauts, tildes, …) from a UTF-8 string
     * by decomposing to NFD and removing every `\p{Mn}` (Mark, Non-spacing)
     * codepoint. This avoids the iconv `//TRANSLIT` failure mode where `é`
     * becomes `'e` and a stray apostrophe leaks into the normalised name.
     */
    private function stripDiacritics(string $s): string
    {
        $decomposed = Normalizer::normalize($s, Normalizer::FORM_D);
        if (! is_string($decomposed)) {
            return $s;
        }

        $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed);

        return is_string($stripped) ? $stripped : $decomposed;
    }
}
