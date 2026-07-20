<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class PatternGeneralizer
{
    public function generalize(string $rawDescription): string
    {
        $split = preg_split('/\s+/', trim($rawDescription));
        $tokens = $split === false ? [] : array_values(array_filter(
            $split,
            static fn (string $token): bool => $token !== '',
        ));
        $kept = [];
        $lastTokenIndex = count($tokens) - 1;

        foreach ($tokens as $i => $token) {
            // PIN-tail: `*NNNN` is only meaningful as the final token —
            // PIN-payment statements terminate with the card's last 4 digits.
            if ($i === $lastTokenIndex && preg_match('/^\*\d{4}$/', $token) === 1) {
                continue;
            }
            // Terminal IDs: bare 4..7 digit run, optionally `T`/`#`-
            // prefixed — ASN PIN rows append a terminal id to the merchant code.
            if (preg_match('/^[T#]?\d{4,7}$/', $token) === 1) {
                continue;
            }
            // SEPA / CAMT noise prefixes (EREF/KENMERK/BKTXCD/MARF/REF) —
            // provenance hints that never identify the merchant.
            if (preg_match('/^(EREF|KENMERK|BKTXCD|MARF|REF):/i', $token) === 1) {
                continue;
            }
            // Amount tokens (`12.50`, `12,50`, `-12,99`) — statement
            // narratives sometimes inline the transaction amount as a token.
            if (preg_match('/^-?\d+[.,]\d{2}$/', $token) === 1) {
                continue;
            }
            // Date fragments: `12-05`, `12-05-2026`, `2026-05-12`,
            // including `/`- and `.`-separated variants.
            if (preg_match('/^\d{2,4}[-.\/]\d{1,2}([-.\/]\d{1,4})?$/', $token) === 1) {
                continue;
            }
            $kept[] = $token;
        }

        $joined = mb_strtolower(implode(' ', $kept));
        $collapsed = preg_replace('/\s+/', ' ', $joined);

        return $collapsed === null ? $joined : trim($collapsed);
    }
}
