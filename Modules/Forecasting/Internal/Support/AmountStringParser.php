<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use InvalidArgumentException;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class AmountStringParser
{
    /**
     * @param  bool  $allowNegative  false rejects negative numbers (used by
     *                               the buffer editor, where a negative buffer is meaningless)
     * @param  bool  $requireNonZero  true rejects zero (used by the
     *                                model-what-if dropdown, where a zero "new amount" is treated as
     *                                a cancel mutation and the dropdown enforces a positive value)
     */
    public static function toMinor(string $input, bool $allowNegative = true, bool $requireNonZero = false): int
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Amount is required.');
        }

        // MoneyInput knows only the '-' a keyboard produces; a typed '+' is
        // a sign this form accepts and the value object never sees.
        $minor = MoneyInput::tryToMinor(str_starts_with($trimmed, '+') ? ltrim(substr($trimmed, 1)) : $trimmed);

        if ($minor === null) {
            throw new InvalidArgumentException('Amount must be a number with at most two decimals.');
        }
        if (! $allowNegative && $minor < 0) {
            throw new InvalidArgumentException('Amount must be zero or positive.');
        }
        if ($requireNonZero && $minor === 0) {
            throw new InvalidArgumentException('Amount must be non-zero.');
        }

        return $minor;
    }
}
