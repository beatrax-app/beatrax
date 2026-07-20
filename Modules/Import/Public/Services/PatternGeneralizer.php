<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

/**
 * Generalizes a raw bank-statement description into a fuzzy-match
 * pattern by stripping volatile tokens. The output stays a literal-
 * substring comparison target — never a regex — so it can be matched
 * safely from PHP without entering SQL.
 *
 * The generalizer is intentionally pure: no constructor state, no
 * collaborators, no side effects. The same input always produces the
 * same output, which means the result can be cached safely on the
 * merchant_aliases row's `generalized_pattern` column and used as both
 * a fuzzy-match target inside MerchantNameResolver and a preview line
 * shown to the user in the rename popover.
 *
 * Algorithm (token classifier + reject list, applied in order):
 *
 *   1. Split the raw description on whitespace into tokens.
 *   2. For each token, classify and drop it when it matches any of:
 *      - pin-tail        — `*NNNN` at the FINAL token slot only;
 *                          PIN-payment statements terminate with the
 *                          last 4 digits of the card.
 *      - terminal-id     — bare digit runs of 4..7 digits, optionally
 *                          prefixed by `T` or `#`. ASN PIN rows append
 *                          a terminal identifier to the merchant code.
 *      - sepa-noise      — tokens that begin with a known noise prefix
 *                          (`EREF:`, `KENMERK:`, `BKTXCD:`, `MARF:`,
 *                          `REF:`). Provenance hints for SEPA / CAMT
 *                          rows that never identify the merchant.
 *      - amount          — `NN.NN` or `NN,NN` with an optional leading
 *                          minus sign. Statement narratives sometimes
 *                          inline the transaction amount as a token.
 *      - date-fragment   — `DD-MM`, `DD-MM-YYYY`, `YYYY-MM-DD` and the
 *                          `/`-separated variants. Booking dates leak
 *                          into the description on some ICS rows.
 *   3. Re-join the surviving tokens with a single space, lower-case
 *      the result, and collapse any residual whitespace runs into a
 *      single space.
 *
 * Examples (verbatim from sketch session 004 fixtures):
 *   "BCK*SHELL PIETER NIEUW *0123"  → "bck*shell pieter nieuw"
 *   "ALBERT HEIJN 1245 T07438"      → "albert heijn"
 *   "iDEAL Bestelling KENMERK:1234" → "ideal bestelling"
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
