<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Normalizer;

/**
 * Produces the canonical SHA-256 fingerprint of a CanonicalTransaction. The
 * fingerprint is the second-layer idempotency guard (D-16) — the composite
 * UNIQUE index on `transactions(account_id, posted_at, amount_minor,
 * currency, counterparty_normalized, source_ref)` is the first.
 *
 * `normalize()` collapses a raw counterparty name into a stable string used
 * inside the fingerprint tuple. NORMALIZATION_VERSION is bumped whenever the
 * algorithm changes so the column on `transactions` (`normalization_version`)
 * lets old rows be re-normalised against a new algorithm without invalidating
 * historic fingerprints.
 */
final class FingerprintComposer
{
    public const NORMALIZATION_VERSION = 1;

    public function compose(CanonicalTransaction $tx): string
    {
        $tuple = implode('|', [
            (string) $tx->accountId,
            $tx->postedAt->toDateString(),
            (string) $tx->amountMinor,
            $tx->currency,
            $tx->counterpartyNormalized,
            $tx->sourceRef ?? '',
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
