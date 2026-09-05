<?php

declare(strict_types=1);

namespace Modules\FX\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\FX\Public\Enums\ConversionOutcome;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\RateTable;

/**
 * @link ../../../../.docs/features/fx/architecture.md
 */
final class ExchangeRateService
{
    public const int STALE_DAYS_THRESHOLD = 3;

    private const string BASE_CURRENCY = Currency::Eur->value;

    // The rate in effect on a date is the newest one published on or before it,
    // resolved per pair: ECB publishes on business days only, so an exact-date
    // match drops every weekend, every holiday, and every date older than the
    // first row. Nothing on or before it falls forward to the oldest row held.
    private const string RATE_DATE_IN_EFFECT = <<<'SQL'
        er.rate_date = COALESCE(
            (SELECT MAX(on_or_before.rate_date) FROM exchange_rates AS on_or_before
              WHERE on_or_before.base_currency = er.base_currency
                AND on_or_before.quote_currency = er.quote_currency
                AND on_or_before.rate_date <= ?),
            (SELECT MIN(on_or_after.rate_date) FROM exchange_rates AS on_or_after
              WHERE on_or_after.base_currency = er.base_currency
                AND on_or_after.quote_currency = er.quote_currency
                AND on_or_after.rate_date >= ?)
        )
        SQL;

    /** @var array<string, Collection<int, \stdClass>> */
    private array $ratesByDate = [];

    public function __construct(private readonly DatabaseManager $db) {}

    public function convertToBase(Money $money, string $targetCurrency): ConversionResult
    {
        if ($money->currency() === $targetCurrency) {
            return ConversionResult::passthrough($money);
        }

        return $this->convertWithRows($money, $targetCurrency, $this->fetchLatestRates());
    }

    public function convertAtDate(Money $money, string $targetCurrency, string $date): ConversionResult
    {
        if ($money->currency() === $targetCurrency) {
            return ConversionResult::passthrough($money);
        }

        return $this->convertWithRows($money, $targetCurrency, $this->ratesForDate($date));
    }

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
            ->orderByRaw('case when er.source = ? then 0 else 1 end', [BundledRates::SOURCE])
            ->get();
    }

    // Memoised for the life of the resolved instance. A net-worth series asks
    // for one bucket date once per account currency line, and which row a date
    // resolves to cannot change while a single render is in flight.
    /**
     * @return Collection<int, \stdClass>
     */
    private function ratesForDate(string $date): Collection
    {
        return $this->ratesByDate[$date] ??= $this->fetchRatesForDate($date);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function fetchRatesForDate(string $date): Collection
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
            ->whereRaw(self::RATE_DATE_IN_EFFECT, [$date, $date])
            ->orderByRaw('case when er.source = ? then 0 else 1 end', [BundledRates::SOURCE])
            ->get();
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     */
    // Rows arrive with the bundled snapshot first, so a live provider's row for
    // the same pair and day overwrites it in both the table and the metadata:
    // the snapshot is a floor, never an answer that outranks a real feed.
    private function convertWithRows(Money $money, string $targetCurrency, Collection $rows): ConversionResult
    {
        if ($rows->isEmpty()) {
            return ConversionResult::noRate($money);
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

            if ($rowDate !== null && (! isset($rateMeta[$quoteC]) || $rowDate >= $rateMeta[$quoteC]['date'])) {
                $rateMeta[$quoteC] = ['date' => $rowDate, 'source' => self::toString($row->source ?? null)];
            }
        }

        $converted = $table->convert($money, $targetCurrency);

        if ($converted === null) {
            // An unavailable pair or an integer-range overflow leaves the amount
            // in its own currency rather than crashing the net-worth render.
            return ConversionResult::noRate($money);
        }

        $rate = $table->rateFor($money->currency(), $targetCurrency);

        [$asOf, $source] = $this->resolveRateMetadata($money->currency(), $targetCurrency, $rateMeta);
        $isStale = $asOf !== null && $asOf->diffInDays(CarbonImmutable::now()->startOfDay()) > self::STALE_DAYS_THRESHOLD;

        return new ConversionResult(
            original: $money,
            converted: $converted,
            outcome: ConversionOutcome::Converted,
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
