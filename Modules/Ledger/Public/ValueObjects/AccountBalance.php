<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#accountbalancequery--caveats-shared-by-all-four-methods
 */
final readonly class AccountBalance
{
    /** @param array<string, int> $minorByCurrency */
    private function __construct(private array $minorByCurrency) {}

    /**
     * @param  array<string, int>  $minorByCurrency
     */
    public static function of(array $minorByCurrency): self
    {
        $lines = array_filter(
            $minorByCurrency,
            static fn (string $currency): bool => $currency !== '',
            ARRAY_FILTER_USE_KEY,
        );
        ksort($lines);

        return new self($lines);
    }

    /**
     * @return array<string, int>
     */
    public function lines(): array
    {
        return $this->minorByCurrency;
    }

    // Zero for a currency this account holds none of, which is the same answer
    // as holding zero of it: a caller asking "how much EUR is on here" wants a
    // figure it can print, and no line is not a different position from none.
    public function in(string $currency): int
    {
        return $this->minorByCurrency[$currency] ?? 0;
    }
}
