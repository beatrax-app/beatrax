<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Modules\Ledger\Public\ValueObjects\Money;

// A minor-unit amount carries magnitude and direction in one integer, so
// subtracting two of them only means "the price moved" while both stay on the
// same side of zero and in one currency. The rejected cases are a refund
// against a charge, and a series re-billed in another currency.
final readonly class AmountMovement
{
    private function __construct(
        public int $priorMinor,
        public int $latestMinor,
        public int $deltaMinor,
        public float $ratioPercent,
    ) {}

    public static function between(Money $prior, Money $latest): ?self
    {
        if ($prior->currency() !== $latest->currency()) {
            return null;
        }

        $priorMinor = $prior->toMinor();
        if ($priorMinor === 0) {
            return null;
        }

        $latestMinor = $latest->toMinor();
        if ($latestMinor !== 0 && ($priorMinor > 0) !== ($latestMinor > 0)) {
            return null;
        }

        $deltaMinor = $latestMinor - $priorMinor;

        return new self(
            priorMinor: $priorMinor,
            latestMinor: $latestMinor,
            deltaMinor: $deltaMinor,
            ratioPercent: abs($deltaMinor) * 100 / abs($priorMinor),
        );
    }

    // Each side is annualised at the rate it was actually billed at, so a
    // monthly-to-yearly restructure reports what the year costs now against
    // what it cost before rather than one period's delta at the new rate.
    public function annualImpactMinor(int $priorPerYear, int $latestPerYear): int
    {
        return $this->latestMinor * $latestPerYear - $this->priorMinor * $priorPerYear;
    }
}
