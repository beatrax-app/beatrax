<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Context\DefaultContext;
use Brick\Money\Currency;
use Brick\Money\CurrencyConverter;
use Brick\Money\Exception\ExchangeRateNotFoundException;
use Brick\Money\ExchangeRateProvider\BaseCurrencyProvider;
use Brick\Money\ExchangeRateProvider\Configurable\ConfigurableProviderBuilder;
use Brick\Money\ExchangeRateProvider\ConfigurableProvider;
use Brick\Money\Money as BrickMoney;
use Throwable;

// Holds the rate provider, converter and rounding mode brick/money keeps
// outside Money, so an FX service composes rates and Money without meeting
// the library. Every failure returns null: an unconvertible amount falls
// back to the caller's passthrough rather than aborting the page.
final class RateTable
{
    /** @param array<string, string> $rates "FROM>TO" => decimal rate */
    private function __construct(
        private readonly ?string $baseCurrency,
        private readonly array $rates,
    ) {}

    // Uses only the pairs it was given, in the direction they were given —
    // for a rate recorded on the transaction itself, which already points the
    // way that transaction needed.
    public static function direct(): self
    {
        return new self(null, []);
    }

    // Derives a missing pair through the base currency, so USD->GBP falls out
    // of two EUR-based rows without either being stored.
    public static function crossedThrough(string $baseCurrency): self
    {
        return new self($baseCurrency, []);
    }

    // A rate that is not a decimal is dropped rather than thrown: one corrupt
    // row must not take the whole table with it.
    public function withRate(string $from, string $to, string $rate): self
    {
        if (Rate::of($rate) === null) {
            return $this;
        }

        return new self($this->baseCurrency, [...$this->rates, $from.'>'.$to => $rate]);
    }

    public function convert(Money $money, string $targetCurrency): ?Money
    {
        try {
            $converter = new CurrencyConverter($this->provider());
            $source = BrickMoney::ofMinor($money->toMinor(), $money->currency());
            $converted = $converter->convert($source, $targetCurrency, context: new DefaultContext, roundingMode: RoundingMode::HalfUp);

            return Money::ofMinor($converted->getMinorAmount()->toInt(), $targetCurrency);
        } catch (ExchangeRateNotFoundException|MathException) {
            return null;
        }
    }

    public function rateFor(string $from, string $to): ?Rate
    {
        try {
            return Rate::of((string) $this->provider()->getExchangeRate(Currency::of($from), Currency::of($to)));
        } catch (Throwable) {
            return null;
        }
    }

    private function provider(): BaseCurrencyProvider|ConfigurableProvider
    {
        $builder = new ConfigurableProviderBuilder;

        foreach ($this->rates as $pair => $rate) {
            [$from, $to] = explode('>', $pair, 2);
            $builder = $builder->addExchangeRate($from, $to, $rate);
        }

        $configured = $builder->build();

        return $this->baseCurrency === null
            ? $configured
            : new BaseCurrencyProvider($configured, $this->baseCurrency);
    }
}
