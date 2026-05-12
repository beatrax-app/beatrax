<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Serialisation-friendly Money envelope: signed integer minor units plus an
 * ISO 4217 currency code. Use the `Money` value object (ValueObjects\Money)
 * for arithmetic; this DTO is for crossing the wire / DB boundary as plain
 * data (e.g. embedded in a Livewire response).
 */
final class MoneyDto extends Data
{
    public function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {}
}
