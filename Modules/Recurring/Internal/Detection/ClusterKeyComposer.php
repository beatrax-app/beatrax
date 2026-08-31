<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detection;

final readonly class ClusterKeyComposer
{
    private const int MAX_PART_LENGTH = 60;

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

    // `/u` and the \p classes are load-bearing twice over: without them a Greek
    // or Cyrillic merchant reduces to an empty token and merges with every other
    // one, and `&` is kept because FingerprintComposer::normalize() keeps it, so
    // `a&b` and `a b` arrive here as two merchants and must leave as two keys.
    private static function normalisePart(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');
        $hyphenated = (string) preg_replace('/[^\p{L}\p{N}&]+/u', '-', $lower);
        $trimmed = trim($hyphenated, '-');

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_PART_LENGTH) {
            $trimmed = mb_substr($trimmed, 0, self::MAX_PART_LENGTH, 'UTF-8');
            $trimmed = rtrim($trimmed, '-');
        }

        return $trimmed;
    }
}
