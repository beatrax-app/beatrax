<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\RateTable;

final class ExchangeRateService
{
    private const int STALE_DAYS_THRESHOLD = 3;

    private const string BASE_CURRENCY = 'EUR';

    public function __construct(private readonly DatabaseManager $db) {}

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

    private function convertWithKnownRate(
        Money $money,
        string $targetCurrency,
        string $knownRate,
        string $date,
    ): ConversionResult {
        // tx.fx_rate_used is already stored in the direction this conversion needs.
        $converted = RateTable::direct()
            ->withRate($money->currency(), $targetCurrency, $knownRate)
            ->convert($money, $targetCurrency);

        if ($converted === null) {
            // null means the direct rate was insufficient (a cross-rate is needed)
            // or the result overflows the platform integer range.
            return $this->convertWithRows($money, $targetCurrency, $this->fetchRatesForDate($date));
        }

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

        $table = RateTable::crossedThrough(self::BASE_CURRENCY);

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

            $table = $table->withRate($baseC, $quoteC, $rateStr);

            $rowDate = self::toString($row->rate_date ?? null);

            if ($rowDate !== null && (! isset($rateMeta[$quoteC]) || $rowDate > $rateMeta[$quoteC]['date'])) {
                $rateMeta[$quoteC] = ['date' => $rowDate, 'source' => self::toString($row->source ?? null)];
            }
        }

        $converted = $table->convert($money, $targetCurrency);

        if ($converted === null) {
            // An unavailable pair or an integer-range overflow degrades to
            // passthrough rather than crashing the whole net-worth render.
            return ConversionResult::passthrough($money);
        }

        $rate = $table->rateFor($money->currency(), $targetCurrency);

        [$asOf, $source] = $this->resolveRateMetadata($money->currency(), $targetCurrency, $rateMeta);
        $isStale = $asOf !== null && $asOf->diffInDays(CarbonImmutable::now()->startOfDay()) > self::STALE_DAYS_THRESHOLD;

        return new ConversionResult(
            original: $money,
            converted: $converted,
            isPassthrough: false,
            rate: $rate === null ? null : (string) $rate,
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
            // The base currency has no exchange_rates row of its own, so that
            // leg is trivially fresh and only non-base legs decide staleness.
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

    private static function toString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
