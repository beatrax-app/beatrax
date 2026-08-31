<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Ledger\Internal\Exceptions\MoneyColumnMissingException;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @implements CastsAttributes<Money, Money>
 */
final readonly class MoneyMinorCast implements CastsAttributes
{
    public function __construct(
        private string $minorColumn = 'amount_minor',
        private string $currencyColumn = 'currency',
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
