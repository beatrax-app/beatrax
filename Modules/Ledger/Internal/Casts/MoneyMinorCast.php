<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * Eloquent cast that bridges a paired `(minor, currency)` column tuple to a
 * Money value object. Reads two columns out of `$attributes`, writes two
 * columns back. The default tuple maps the native amount; pass column names
 * as cast arguments (`MoneyMinorCast::class . ':settled_amount_minor,settled_currency'`)
 * to point the cast at a different pair.
 *
 * @implements CastsAttributes<Money, Money>
 */
final class MoneyMinorCast implements CastsAttributes
{
    public function __construct(
        private readonly string $minorColumn = 'amount_minor',
        private readonly string $currencyColumn = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        $minor = $attributes[$this->minorColumn] ?? 0;
        $currency = $attributes[$this->currencyColumn] ?? 'EUR';

        return Money::ofMinor(
            is_numeric($minor) ? (int) $minor : 0,
            is_string($currency) ? $currency : 'EUR',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                'Money cast expects '.Money::class.', got '.get_debug_type($value),
            );
        }

        return [
            $this->minorColumn => $value->toMinor(),
            $this->currencyColumn => $value->currency(),
        ];
    }
}
