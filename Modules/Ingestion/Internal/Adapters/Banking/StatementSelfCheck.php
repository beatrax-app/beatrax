<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

/**
 * @link ../../../../../.docs/features/ingestion/a-statement-that-did-not-check-its-own-arithmetic.md
 */
final class StatementSelfCheck
{
    // Null where there is nothing to check against — a balance the file did not
    // state, or two stated in different currencies — and null again where the
    // statement adds up, because a zero difference puts nothing on the record.
    public static function differenceMinor(
        ?int $openingMinor,
        ?string $openingCurrency,
        ?int $closingMinor,
        ?string $closingCurrency,
        ?int $netMinor,
    ): ?int {
        if ($openingMinor === null || $closingMinor === null || $netMinor === null) {
            return null;
        }

        // Minor units of two currencies do not subtract.
        if ($openingCurrency === null || $openingCurrency !== $closingCurrency) {
            return null;
        }

        $difference = $closingMinor - ($openingMinor + $netMinor);

        return $difference === 0 ? null : $difference;
    }
}
