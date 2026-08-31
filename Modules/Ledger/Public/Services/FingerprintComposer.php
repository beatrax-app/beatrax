<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Normalizer;

final class FingerprintComposer
{
    // Bump whenever the tuple shape or normalize()'s output changes;
    // re-derive existing rows via beatrax:rederive-fingerprints.
    public const int NORMALIZATION_VERSION = 3;

    public function compose(CanonicalTransaction $tx): string
    {
        return $this->composeTuple(
            $tx->userId ?? 0,
            $tx->accountId,
            $tx->postedAt->toDateString(),
            $tx->bookedAt->toDateTimeString(),
            $tx->amountMinor,
            $tx->currency,
            $tx->counterpartyNormalized,
        );
    }

    // The same tuple over values read straight from a row, for the sweeps that
    // rewrite counterparty_normalized and must rewrite the fingerprint with it
    // rather than rebuild a whole canonical DTO to reach seven of its fields.
    public function composeTuple(
        int $userId,
        int $accountId,
        string $postedAtDate,
        string $bookedAtDateTime,
        int $amountMinor,
        string $currency,
        string $counterpartyNormalized,
    ): string {
        return hash('sha256', implode('|', [
            (string) $userId,
            (string) $accountId,
            $postedAtDate,
            $bookedAtDateTime,
            (string) $amountMinor,
            $currency,
            $counterpartyNormalized,
        ]));
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

    // Decomposes to NFD and removes every \p{Mn} codepoint, avoiding the
    // iconv //TRANSLIT failure mode where e-acute becomes "'e" and a
    // stray apostrophe leaks into the normalised name.
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
