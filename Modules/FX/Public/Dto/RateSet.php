<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

// The rates one roll-up converts at, carried as what they are rather than as
// bare numbers. It was an array<string, string> of code => rate, which threw
// away the source and the as-of date the moment they were read, and left every
// surface downstream unable to disclose a rate it had itself converted at.
/**
 * @link ../../../../.docs/features/fx/architecture.md#a-converted-figure-carries-the-rate-that-made-it
 */
final readonly class RateSet
{
    /**
     * @param  array<string, RateUsed>  $rates  keyed by the currency converted FROM
     */
    private function __construct(
        public string $targetCurrency,
        private array $rates,
    ) {}

    public static function empty(string $targetCurrency): self
    {
        return new self($targetCurrency, []);
    }

    /**
     * @param  array<string, RateUsed>  $rates
     */
    public static function of(string $targetCurrency, array $rates): self
    {
        ksort($rates);

        return new self($targetCurrency, $rates);
    }

    public function with(RateUsed $rate): self
    {
        return self::of($this->targetCurrency, [...$this->rates, $rate->from => $rate]);
    }

    public function has(string $currency): bool
    {
        return isset($this->rates[$currency]);
    }

    public function rateFor(string $currency): ?string
    {
        return ($this->rates[$currency] ?? null)?->rate;
    }

    public function usedFor(string $currency): ?RateUsed
    {
        return $this->rates[$currency] ?? null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->rates);
    }

    /** @return list<RateUsed> */
    public function all(): array
    {
        return array_values($this->rates);
    }

    public function isEmpty(): bool
    {
        return $this->rates === [];
    }

    // Narrowed to the currencies a figure was actually built from, so a
    // dashboard tile holding euros and yen does not disclose the pound rate
    // that happened to be in the same batched read.
    /**
     * @param  list<string>  $currencies
     */
    public function only(array $currencies): self
    {
        $kept = [];

        foreach ($currencies as $currency) {
            if (isset($this->rates[$currency])) {
                $kept[$currency] = $this->rates[$currency];
            }
        }

        return self::of($this->targetCurrency, $kept);
    }
}
