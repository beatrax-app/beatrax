<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detection;

final readonly class ClusterKeyComposer
{
    private const MAX_PART_LENGTH = 60;

    public function compose(
        string $direction,
        string $counterpartyKey,
        string $originalCurrencyCode,
        string $cadenceBand,
    ): string {
        $direction = self::normalisePart($direction);
        $counterparty = self::normalisePart($counterpartyKey);
        $currency = self::normalisePart($originalCurrencyCode);
        $cadence = self::normalisePart($cadenceBand);

        return $direction.'::'.$counterparty.'::'.$currency.'::'.$cadence;
    }

    private static function normalisePart(string $value): string
    {
        $lower = strtolower($value);
        $hyphenated = (string) preg_replace('/[^a-z0-9]+/', '-', $lower);
        $trimmed = trim($hyphenated, '-');

        if (strlen($trimmed) > self::MAX_PART_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_PART_LENGTH);
            $trimmed = rtrim($trimmed, '-');
        }

        return $trimmed;
    }
}
