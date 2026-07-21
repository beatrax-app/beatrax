<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Ledger\Public\Exceptions\MoneyColumnMissingException;
use Modules\Ledger\Public\ValueObjects\Money;

// Reads/writes a paired (minor, currency) column tuple as a Money
// value object; pass column names as cast arguments to point at a
// different pair than the default amount_minor/currency.
/**
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
        if (! array_key_exists($this->minorColumn, $attributes) || ! array_key_exists($this->currencyColumn, $attributes)) {
            throw new MoneyColumnMissingException($this->minorColumn, $this->currencyColumn);
        }

        $minor = $attributes[$this->minorColumn];
        $currency = $attributes[$this->currencyColumn];

        if (! is_numeric($minor) || ! is_string($currency)) {
            throw new MoneyColumnMissingException($this->minorColumn, $this->currencyColumn);
        }

        return Money::ofMinor((int) $minor, $currency);
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
