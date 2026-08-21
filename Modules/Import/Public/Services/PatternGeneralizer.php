<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class PatternGeneralizer
{
    private const string CARD_TAIL_PATTERN = '/^\*\d{4}$/';

    private const string TERMINAL_ID_PATTERN = '/^[T#]?\d{4,7}$/';

    private const string PROVENANCE_PREFIX_PATTERN = '/^(EREF|KENMERK|BKTXCD|MARF|REF):/i';

    private const string INLINE_AMOUNT_PATTERN = '/^-?\d+[.,]\d{2}$/';

    private const string DATE_PATTERN = '/^\d{2,4}[-.\/]\d{1,2}([-.\/]\d{1,4})?$/';

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
            // A PIN statement ends with the card's last 4 digits, so `*NNNN`
            // only means that as the final token.
            if ($i === $lastTokenIndex && preg_match(self::CARD_TAIL_PATTERN, $token) === 1) {
                continue;
            }
            if (preg_match(self::TERMINAL_ID_PATTERN, $token) === 1) {
                continue;
            }
            if (preg_match(self::PROVENANCE_PREFIX_PATTERN, $token) === 1) {
                continue;
            }
            if (preg_match(self::INLINE_AMOUNT_PATTERN, $token) === 1) {
                continue;
            }
            if (preg_match(self::DATE_PATTERN, $token) === 1) {
                continue;
            }
            $kept[] = $token;
        }

        $joined = mb_strtolower(implode(' ', $kept));
        $collapsed = preg_replace('/\s+/', ' ', $joined);

        return $collapsed === null ? $joined : trim($collapsed);
    }
}
