<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Brick\Math\RoundingMode;
use Brick\Money\Context\DefaultContext;
use Brick\Money\CurrencyConverter;
use Brick\Money\Exception\CurrencyConversionException;
use Brick\Money\ExchangeRateProvider\BaseCurrencyProvider;
use Brick\Money\ExchangeRateProvider\ConfigurableProvider;
use Brick\Money\Money as BrickMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * Single cross-module entry point for currency conversion.
 *
 * Two methods:
 *  - `convertToBase()` for current-snapshot figures (D-08): uses the latest
 *    available rate from `exchange_rates`, ordered by rate_date DESC.
 *  - `convertAtDate()` for historical figures (D-09): prefers the caller-supplied
 *    `$knownRate` (tx.fx_rate_used) then falls back to the dated snapshot row.
 *
 * D-03 passthrough: when figure currency == target currency, returns
 * `ConversionResult::passthrough()` — no DB query fires; zero overhead.
 *
 * All DECIMAL reads from PDO are cast to `(string)` before passing to
 * brick/money to avoid float contamination (Pitfall 1 / T-02-03).
 *
 * Conversion uses `BaseCurrencyProvider` with EUR as the base so cross-rates
 * (e.g. USD→GBP) are derived exactly from two EUR-based pairs (D-05 / RESEARCH
 * Pattern 10).
 *
 * Staleness threshold: 3 calendar days (calibrated to the ECB weekend gap —
 * Fri→Mon is 3 days, so Monday morning still shows non-stale rates from Friday).
 */
final class ExchangeRateService
{
    private const int STALE_DAYS_THRESHOLD = 3;

    private const string BASE_CURRENCY = 'EUR';

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Convert $money to $targetCurrency at the latest available rate (D-08).
     * Zero-overhead passthrough when currencies match (D-03).
     */
    public function convertToBase(Money $money, string $targetCurrency): ConversionResult
    {
        if ($money->currency() === $targetCurrency) {
            return ConversionResult::passthrough($money);
        }

        $rows = $this->fetchLatestRates();

        return $this->convertWithRows($money, $targetCurrency, $rows);
    }

    /**
     * Convert $money to $targetCurrency at the rate applicable to $date (D-09).
     *
     * Preference order:
     * 1. $knownRate (tx.fx_rate_used) — caller supplies this when available.
     * 2. `exchange_rates` row whose rate_date = $date.
     */
    public function convertAtDate(
        Money $money,
        string $targetCurrency,
        string $date,
        ?string $knownRate = null,
    ): ConversionResult {
        if ($money->currency() === $targetCurrency) {
            return ConversionResult::passthrough($money);
        }

        if ($knownRate !== null) {
            return $this->convertWithKnownRate($money, $targetCurrency, $knownRate, $date);
        }

        $rows = $this->fetchRatesForDate($date);

        return $this->convertWithRows($money, $targetCurrency, $rows);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return Collection<int, \stdClass>
     */
    private function fetchLatestRates(): Collection
    {
        // Select the most-recent row per (base, quote) pair using a subquery
        // so we always get the freshest rate regardless of source.
        return $this->db->connection()
            ->table('exchange_rates as er')
            ->select([
                'er.base_currency',
                'er.quote_currency',
                'er.rate',
                'er.rate_date',
                'er.source',
            ])
            ->whereRaw('er.rate_date = (
                SELECT MAX(inner_er.rate_date)
                FROM exchange_rates AS inner_er
                WHERE inner_er.base_currency = er.base_currency
                  AND inner_er.quote_currency = er.quote_currency
            )')
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function fetchRatesForDate(string $date): Collection
    {
        return $this->db->connection()
            ->table('exchange_rates')
            ->where('rate_date', $date)
            ->get(['base_currency', 'quote_currency', 'rate', 'rate_date', 'source']);
    }

    /**
     * Convert using a known rate string from a transaction's fx_rate_used column.
     * Source is 'transaction'; staleness is always false (the recorded rate is canonical).
     */
    private function convertWithKnownRate(
        Money $money,
        string $targetCurrency,
        string $knownRate,
        string $date,
    ): ConversionResult {
        // For a direct pair (source→target), tx.fx_rate_used stores the rate in the
        // direction the transaction needed. Use it as-is via ConfigurableProvider.
        $configProvider = new ConfigurableProvider;
        $configProvider->setExchangeRate($money->currency(), $targetCurrency, $knownRate);

        try {
            $converter = new CurrencyConverter($configProvider);
            $brickSource = BrickMoney::ofMinor($money->toMinor(), $money->currency());
            $brickResult = $converter->convert($brickSource, $targetCurrency, new DefaultContext, RoundingMode::HALF_UP);
        } catch (CurrencyConversionException) {
            // If the direct rate is insufficient (cross-rate needed), fall back to DB rows.
            return $this->convertWithRows($money, $targetCurrency, $this->fetchRatesForDate($date));
        }

        $converted = Money::ofMinor($brickResult->getMinorAmount()->toInt(), $targetCurrency);

        return new ConversionResult(
            original: $money,
            converted: $converted,
            isPassthrough: false,
            rate: $knownRate,
            source: 'transaction',
            asOf: CarbonImmutable::parse($date),
            isStale: false,
        );
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     */
    private function convertWithRows(Money $money, string $targetCurrency, Collection $rows): ConversionResult
    {
        if ($rows->isEmpty()) {
            // No rows available — return passthrough with original (D-07 no-rate fallback)
            return ConversionResult::passthrough($money);
        }

        // Build a ConfigurableProvider from the rows (all EUR-based pairs)
        $configProvider = new ConfigurableProvider;
        $latestDate = null;
        $source = null;

        foreach ($rows as $row) {
            $baseC = self::toString($row->base_currency ?? null);
            $quoteC = self::toString($row->quote_currency ?? null);
            $rateRaw = $row->rate ?? null;

            if ($baseC === null || $quoteC === null || $rateRaw === null) {
                continue;
            }

            // Cast to string — never float (Pitfall 1 / T-02-03)
            $rateStr = self::toString($rateRaw);

            if ($rateStr === null) {
                continue;
            }

            $configProvider->setExchangeRate($baseC, $quoteC, $rateStr);

            // Track the most recent date + source for metadata
            $rowDate = self::toString($row->rate_date ?? null);

            if ($rowDate !== null && ($latestDate === null || $rowDate > $latestDate)) {
                $latestDate = $rowDate;
                $source = self::toString($row->source ?? null);
            }
        }

        // Use BaseCurrencyProvider with EUR as base so cross-rates derive automatically
        $basedProvider = new BaseCurrencyProvider($configProvider, self::BASE_CURRENCY);

        try {
            $converter = new CurrencyConverter($basedProvider);
            $brickSource = BrickMoney::ofMinor($money->toMinor(), $money->currency());
            $brickResult = $converter->convert($brickSource, $targetCurrency, new DefaultContext, RoundingMode::HALF_UP);
        } catch (CurrencyConversionException) {
            // Rate pair unavailable — fall back to passthrough (D-07)
            return ConversionResult::passthrough($money);
        }

        $converted = Money::ofMinor($brickResult->getMinorAmount()->toInt(), $targetCurrency);

        // Determine the rate string for the effective pair used
        $rateStr = $this->deriveRateString($money->currency(), $targetCurrency, $basedProvider);

        $asOf = $latestDate !== null ? CarbonImmutable::parse($latestDate) : null;
        $isStale = $asOf !== null && $asOf->diffInDays(CarbonImmutable::now()->startOfDay()) > self::STALE_DAYS_THRESHOLD;

        return new ConversionResult(
            original: $money,
            converted: $converted,
            isPassthrough: false,
            rate: $rateStr,
            source: $source,
            asOf: $asOf,
            isStale: $isStale,
        );
    }

    /**
     * Derives the effective rate string for the given pair from the provider.
     * Returns null when not derivable (e.g. unexpected exception).
     */
    private function deriveRateString(string $fromCurrency, string $toCurrency, BaseCurrencyProvider $provider): ?string
    {
        try {
            $rate = $provider->getExchangeRate($fromCurrency, $toCurrency);

            return (string) $rate;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function toString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
