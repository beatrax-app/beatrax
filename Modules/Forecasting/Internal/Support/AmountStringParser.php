<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use InvalidArgumentException;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class AmountStringParser
{
    /**
     * @param  string  $currency  the ISO-4217 code the typed figure is
     *                            denominated in. A yen has no minor unit, so the
     *                            repo-wide two decimals stored 100x what was
     *                            typed and drew it a hundredth of its size.
     * @param  bool  $allowNegative  false rejects negative numbers (used by
     *                               the buffer editor, where a negative buffer is meaningless)
     * @param  bool  $requireNonZero  true rejects zero (used by the
     *                                model-what-if dropdown, where a zero "new amount" is treated as
     *                                a cancel mutation and the dropdown enforces a positive value)
     */
    public static function toMinor(
        string $input,
        string $currency,
        bool $allowNegative = true,
        bool $requireNonZero = false,
    ): int {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidArgumentException(Lang::get('forecasting::forecast.errors.amount_required'));
        }

        // MoneyInput knows only the '-' a keyboard produces; a typed '+' is
        // a sign this form accepts and the value object never sees.
        $minor = MoneyInput::tryToMinor(
            str_starts_with($trimmed, '+') ? ltrim(substr($trimmed, 1)) : $trimmed,
            $currency,
        );

        if ($minor === null) {
            throw new InvalidArgumentException(self::shapeMessageFor($currency));
        }
        if (! $allowNegative && $minor < 0) {
            throw new InvalidArgumentException(Lang::get('forecasting::forecast.errors.amount_non_negative'));
        }
        if ($requireNonZero && $minor === 0) {
            throw new InvalidArgumentException(Lang::get('forecasting::forecast.errors.amount_non_zero'));
        }

        return $minor;
    }

    // "at most two decimals" is a false statement about a zero-decimal
    // currency, and the box that produced it refuses the only shape a yen
    // has.
    private static function shapeMessageFor(string $currency): string
    {
        $decimals = CurrencyScale::decimals($currency);

        return $decimals === 0
            ? Lang::get('forecasting::forecast.errors.amount_whole')
            : Lang::choice('forecasting::forecast.errors.amount_decimals', $decimals, ['decimals' => $decimals]);
    }
}
