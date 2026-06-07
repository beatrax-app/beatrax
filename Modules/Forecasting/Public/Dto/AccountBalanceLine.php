<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One account's current balance for the net-worth roll-up. `balanceMinor` is
 * already sign-correct: assets (bank, PayPal) are positive, a credit card is
 * negative (the amount owed), as encoded by the balance-anchor resolver.
 */
final class AccountBalanceLine extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $balanceMinor,
        public readonly string $currency,
        public readonly bool $isLiability,
    ) {}
}
