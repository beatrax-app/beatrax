<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Brick\Math\Exception\MathException;
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
 * @link ../../../../.docs/features/fx/architecture.md
 */
final class ExchangeRateService
{
    private const int STALE_DAYS_THRESHOLD = 3;

    private const string BASE_CURRENCY = 'EUR';

    public function __construct(private readonly DatabaseManager $db) {}

    // Converts at the latest available rate; zero-overhead passthrough
    // when currencies match.
    public function convertToBase(Money $money, string $targetCurrency): ConversionResult
    {
        if ($money->currency() === $targetCurrency) {
            return ConversionResult::passthrough($money);
        }

        $rows = $this->fetchLatestRates();

        return $this->convertWithRows($money, $targetCurrency, $rows);
    }

    /**
     * @param  string  $date  used to look up the dated snapshot row when
     *                        $knownRate is not supplied
     * @param  ?string  $knownRate  caller-supplied rate (tx.fx_rate_used),
     *                              preferred over the dated snapshot row
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

    // Converts using a known rate string from a transaction's
    // fx_rate_used column; source is 'transaction' and staleness is
    // always false since the recorded rate is canonical.
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
            $convertedMinor = $brickResult->getMinorAmount()->toInt();
        } catch (CurrencyConversionException|MathException) {
            // Direct rate insufficient (cross-rate needed) or the result overflows
            // the platform integer range — fall back to the dated DB rows.
            return $this->convertWithRows($money, $targetCurrency, $this->fetchRatesForDate($date));
        }

        $converted = Money::ofMinor($convertedMinor, $targetCurrency);

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
            return ConversionResult::passthrough($money);
        }

        // Track the freshest date + source PER quote currency below so the
        // result metadata reflects the pair(s) actually used in this
        // conversion, not just the globally newest row (which could
        // mis-report a genuinely stale pair as fresh).
        $configProvider = new ConfigurableProvider;

        /** @var array<string, array{date: string, source: ?string}> $rateMeta */
        $rateMeta = [];

        foreach ($rows as $row) {
            $baseC = self::toString($row->base_currency ?? null);
            $quoteC = self::toString($row->quote_currency ?? null);
            $rateRaw = $row->rate ?? null;

            if ($baseC === null || $quoteC === null || $rateRaw === null) {
                continue;
            }

            $rateStr = self::toString($rateRaw);

            if ($rateStr === null) {
                continue;
            }

            $configProvider->setExchangeRate($baseC, $quoteC, $rateStr);

            $rowDate = self::toString($row->rate_date ?? null);

            if ($rowDate !== null && (! isset($rateMeta[$quoteC]) || $rowDate > $rateMeta[$quoteC]['date'])) {
                $rateMeta[$quoteC] = ['date' => $rowDate, 'source' => self::toString($row->source ?? null)];
            }
        }

        // BaseCurrencyProvider derives cross-rates automatically from
        // EUR-based pairs (e.g. USD->GBP from two EUR-based rows).
        $basedProvider = new BaseCurrencyProvider($configProvider, self::BASE_CURRENCY);

        try {
            $converter = new CurrencyConverter($basedProvider);
            $brickSource = BrickMoney::ofMinor($money->toMinor(), $money->currency());
            $brickResult = $converter->convert($brickSource, $targetCurrency, new DefaultContext, RoundingMode::HALF_UP);
            $convertedMinor = $brickResult->getMinorAmount()->toInt();
        } catch (CurrencyConversionException|MathException) {
            // Rate pair unavailable, or the converted amount overflows the
            // platform integer range — fall back to passthrough rather
            // than crashing the caller (e.g. the whole net-worth render).
            return ConversionResult::passthrough($money);
        }

        $converted = Money::ofMinor($convertedMinor, $targetCurrency);

        $rateStr = $this->deriveRateString($money->currency(), $targetCurrency, $basedProvider);

        // As-of / source / staleness reflect the OLDEST rate actually involved
        // in this conversion (the non-base legs), so any stale leg flags the
        // whole figure as stale.
        [$asOf, $source] = $this->resolveRateMetadata($money->currency(), $targetCurrency, $rateMeta);
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
     * @param  array<string, array{date: string, source: ?string}>  $rateMeta
     * @return array{0: ?CarbonImmutable, 1: ?string}
     */
    private function resolveRateMetadata(string $fromCurrency, string $toCurrency, array $rateMeta): array
    {
        $oldestDate = null;
        $source = null;

        foreach ([$fromCurrency, $toCurrency] as $currency) {
            // The base currency (EUR) has no row of its own — that leg
            // is trivially fresh, so only non-base legs are considered.
            if ($currency === self::BASE_CURRENCY || ! isset($rateMeta[$currency])) {
                continue;
            }

            $date = $rateMeta[$currency]['date'];

            if ($oldestDate === null || $date < $oldestDate) {
                $oldestDate = $date;
                $source = $rateMeta[$currency]['source'];
            }
        }

        return [
            $oldestDate !== null ? CarbonImmutable::parse($oldestDate) : null,
            $source,
        ];
    }

    // Derives the effective rate string for the given pair; returns null
    // when not derivable (e.g. an unexpected exception).
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
