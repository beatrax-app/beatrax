<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Modules\FX\Public\Dto\ConvertedTotal;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\RateTable;

// Adding minor units across currencies is the arithmetic AccountBalance exists
// to prevent. Every roll-up that spans them converts each bucket at its own
// rate, leaves out a currency the rate table cannot reach rather than counting
// it at one to one, and names what it left out.
/**
 * @link ../../../../.docs/features/fx/architecture.md
 */
final readonly class CrossCurrencyTotal
{
    public function __construct(private ExchangeRateService $fx) {}

    /**
     * @param  array<string, int>  $minorByCurrency
     */
    public function of(array $minorByCurrency, string $targetCurrency): ConvertedTotal
    {
        return $this->withRates(
            $minorByCurrency,
            $targetCurrency,
            $this->ratesTo(array_keys($minorByCurrency), $targetCurrency),
        );
    }

    // One lookup per currency, never one per bucket: convertToBase() reads the
    // whole exchange_rates table on every call. The rate a zero amount converts
    // at is the rate any amount converts at.
    /**
     * @param  list<string>  $currencies
     * @return array<string, string> currency code => decimal rate into $targetCurrency
     */
    public function ratesTo(array $currencies, string $targetCurrency): array
    {
        $rates = [];
        foreach (array_unique($currencies) as $currency) {
            if ($currency === $targetCurrency || $currency === '' || isset($rates[$currency])) {
                continue;
            }

            $probe = Money::tryOfMinor(0, $currency);
            $rate = $probe === null ? null : $this->fx->convertToBase($probe, $targetCurrency)->rate;

            if ($rate !== null) {
                $rates[$currency] = $rate;
            }
        }

        return $rates;
    }

    /**
     * @param  array<string, int>  $minorByCurrency
     * @param  array<string, string>  $rates  as returned by ratesTo()
     */
    public function withRates(array $minorByCurrency, string $targetCurrency, array $rates): ConvertedTotal
    {
        $minor = 0;
        $unconverted = [];

        foreach ($minorByCurrency as $currency => $bucketMinor) {
            if ($currency === $targetCurrency) {
                $minor += $bucketMinor;

                continue;
            }

            $money = Money::tryOfMinor($bucketMinor, $currency);
            $converted = $money === null ? null : $this->convert($money, $targetCurrency, $rates);

            if ($converted === null) {
                $unconverted[$currency] = true;

                continue;
            }

            $minor += $converted->toMinor();
        }

        $codes = array_keys($unconverted);
        sort($codes);

        return new ConvertedTotal(minor: $minor, currency: $targetCurrency, unconverted: $codes);
    }

    // Null rather than the original amount for a pair with no rate, so a caller
    // that renders the result under the target currency's sign cannot print an
    // unconverted figure there.
    /**
     * @param  array<string, string>  $rates  as returned by ratesTo()
     */
    public function convert(Money $money, string $targetCurrency, array $rates): ?Money
    {
        if ($money->currency() === $targetCurrency) {
            return $money;
        }

        $rate = $rates[$money->currency()] ?? null;

        if ($rate === null) {
            return null;
        }

        return RateTable::direct()
            ->withRate($money->currency(), $targetCurrency, $rate)
            ->convert($money, $targetCurrency);
    }
}
