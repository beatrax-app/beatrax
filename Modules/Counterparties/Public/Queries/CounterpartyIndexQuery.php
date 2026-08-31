<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Fmt;
use Modules\Counterparties\Internal\Enums\CounterpartyTypeFilter;
use Modules\Counterparties\Internal\Support\RollingTwelveMonths;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class CounterpartyIndexQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
        private CrossCurrencyTotal $fx,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @return Collection<int, CounterpartyIndexRow>
     */
    public function forUser(User $user, CounterpartyTypeFilter $typeFilter = CounterpartyTypeFilter::All): Collection
    {
        $column = $typeFilter->toColumnValue();

        $query = $this->db->connection()->table('counterparties')->where('user_id', $user->id);
        if ($column !== null) {
            $query = $query->where('type', $column->value);
        }

        // Never order by display_name here: it is ciphertext once encryption
        // is on. orderBy('id') only makes iteration deterministic — the
        // user-facing order is the post-decrypt usort() below.
        /** @var iterable<stdClass> $cpRows */
        $cpRows = $query->orderBy('id')->get(['id', 'slug', 'display_name', 'type', 'metadata']);

        // One window for the headline total, the average and the bars: read as
        // a rolling year, the total held days the sparkline had no bar for.
        $now = $this->clock->now();
        $cutoffDate = RollingTwelveMonths::startDate($now);

        $buckets = $this->bucketsByCounterparty($user, $cutoffDate);
        $recentRows = $this->recentRowByCounterparty($user);
        $monthlyBuckets = $this->monthlyBucketsByCounterparty($user, $cutoffDate);
        $sparklineMonths = RollingTwelveMonths::months($now);

        // One rate lookup per currency for the whole page, not one per
        // counterparty or per sparkline month: convertToBase() reads the entire
        // exchange_rates table on every call.
        $baseCurrency = $this->baseCurrency->forUser($user);
        $rates = $this->fx->ratesTo($this->currenciesIn($buckets, $monthlyBuckets), $baseCurrency);

        $totals = $this->convertedTotals($buckets, $baseCurrency, $rates);
        $monthlyTotals = $this->convertedMonthlyTotals($monthlyBuckets, $baseCurrency, $rates);

        /** @var list<CounterpartyIndexRow> $result */
        $result = [];
        foreach ($cpRows as $cpRow) {
            $row = $this->buildRow($cpRow, $user, $totals, $recentRows, $monthlyTotals, $sparklineMonths, $baseCurrency);
            if ($row !== null) {
                $result[] = $row;
            }
        }

        usort($result, static function (CounterpartyIndexRow $a, CounterpartyIndexRow $b): int {
            $order = abs($b->total12mMinor) <=> abs($a->total12mMinor);

            return $order !== 0 ? $order : strcmp($a->displayName, $b->displayName);
        });

        return new Collection($result);
    }

    /**
     * @param  array<int, array{total: int, count: int, unconverted: list<string>}>  $totals
     * @param  array<int, stdClass>  $recentRows
     * @param  array<int, array<string, int>>  $monthlyTotals
     * @param  list<string>  $sparklineMonths
     */
    private function buildRow(
        stdClass $cpRow,
        User $user,
        array $totals,
        array $recentRows,
        array $monthlyTotals,
        array $sparklineMonths,
        string $baseCurrency,
    ): ?CounterpartyIndexRow {
        $cpId = is_numeric($cpRow->id ?? null) ? (int) $cpRow->id : 0;
        if ($cpId === 0) {
            return null;
        }

        $userId = $user->id;
        $slug = is_string($cpRow->slug ?? null) ? $cpRow->slug : '';
        $storedDisplayName = is_string($cpRow->display_name ?? null) ? $cpRow->display_name : '';
        $displayName = $storedDisplayName === ''
            ? ''
            : $this->codec->decryptValue('counterparties', 'display_name', $storedDisplayName, $userId, $this->session)['value'];
        $displayName = CounterpartyDefaultName::resolve($displayName, $cpRow->metadata ?? null);
        $type = is_string($cpRow->type ?? null) ? $cpRow->type : CounterpartyType::Unknown->value;

        $total = $totals[$cpId]['total'] ?? 0;
        $count = $totals[$cpId]['count'] ?? 0;
        $avg = $count > 0 ? (int) round($total / RollingTwelveMonths::MONTHS) : 0;

        $perMonth = $monthlyTotals[$cpId] ?? [];
        $sparkline = [];
        foreach ($sparklineMonths as $month) {
            $sparkline[] = $perMonth[$month] ?? 0;
        }

        return new CounterpartyIndexRow(
            id: $cpId,
            slug: $slug,
            displayName: $displayName,
            type: $type,
            total12mMinor: $total,
            avgPerMonthMinor: $avg,
            recentLine: $this->recentLineFrom($recentRows[$cpId] ?? null, $userId),
            sparkline: $sparkline,
            currency: $baseCurrency,
            unconverted: $totals[$cpId]['unconverted'] ?? [],
        );
    }

    /**
     * @param  array<int, array{minor: array<string, int>, count: int}>  $buckets
     * @param  array<int, array<string, array<string, int>>>  $monthlyBuckets
     * @return list<string>
     */
    private function currenciesIn(array $buckets, array $monthlyBuckets): array
    {
        $seen = [];
        foreach ($buckets as $bucket) {
            foreach (array_keys($bucket['minor']) as $currency) {
                $seen[$currency] = true;
            }
        }
        foreach ($monthlyBuckets as $byMonth) {
            foreach ($byMonth as $byCurrency) {
                foreach (array_keys($byCurrency) as $currency) {
                    $seen[$currency] = true;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  array<int, array{minor: array<string, int>, count: int}>  $buckets
     * @param  array<string, string>  $rates
     * @return array<int, array{total: int, count: int, unconverted: list<string>}>
     */
    private function convertedTotals(array $buckets, string $baseCurrency, array $rates): array
    {
        $totals = [];
        foreach ($buckets as $cpId => $bucket) {
            $converted = $this->fx->withRates($bucket['minor'], $baseCurrency, $rates);
            $totals[$cpId] = [
                'total' => $converted->minor,
                'count' => $bucket['count'],
                'unconverted' => $converted->unconverted,
            ];
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, array<string, int>>>  $monthlyBuckets
     * @param  array<string, string>  $rates
     * @return array<int, array<string, int>>
     */
    private function convertedMonthlyTotals(array $monthlyBuckets, string $baseCurrency, array $rates): array
    {
        $monthly = [];
        foreach ($monthlyBuckets as $cpId => $byMonth) {
            foreach ($byMonth as $ym => $byCurrency) {
                $monthly[$cpId][$ym] = $this->fx->withRates($byCurrency, $baseCurrency, $rates)->minor;
            }
        }

        return $monthly;
    }

    // settled_amount_minor, not amount_minor: a roll-up is what the account was
    // actually debited, and it is grouped by the currency it was debited in
    // rather than added straight across it.
    /**
     * @return array<int, array{minor: array<string, int>, count: int}>
     */
    private function bucketsByCounterparty(User $user, string $cutoffDate): array
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('counterparty_id')
            ->where('posted_at', '>=', $cutoffDate)
            ->groupBy('counterparty_id', 'settled_currency')
            ->selectRaw('counterparty_id, settled_currency, COALESCE(SUM(settled_amount_minor), 0) as total, COUNT(*) as cnt')
            ->get();

        $buckets = [];
        foreach ($rows as $row) {
            $cpId = is_numeric($row->counterparty_id ?? null) ? (int) $row->counterparty_id : 0;
            $currency = is_string($row->settled_currency ?? null) ? $row->settled_currency : '';
            if ($cpId === 0 || $currency === '') {
                continue;
            }

            $buckets[$cpId]['minor'][$currency] = is_numeric($row->total ?? null) ? (int) $row->total : 0;
            $buckets[$cpId]['count'] = ($buckets[$cpId]['count'] ?? 0)
                + (is_numeric($row->cnt ?? null) ? (int) $row->cnt : 0);
        }

        return $buckets;
    }

    // Ranked in SQL rather than one ->first() per counterparty. The window's
    // ORDER BY is the tie-break the per-row query used, so a counterparty with
    // two transactions on one date still resolves to the same row.
    /**
     * @return array<int, stdClass>
     */
    private function recentRowByCounterparty(User $user): array
    {
        $connection = $this->db->connection();

        $ranked = $connection->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('counterparty_id')
            ->selectRaw('counterparty_id, posted_at, description, counterparty_name, ROW_NUMBER() OVER (PARTITION BY counterparty_id ORDER BY posted_at DESC, id DESC) as rn');

        /** @var iterable<stdClass> $rows */
        $rows = $connection->query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->get(['counterparty_id', 'posted_at', 'description', 'counterparty_name']);

        $recent = [];
        foreach ($rows as $row) {
            $cpId = is_numeric($row->counterparty_id ?? null) ? (int) $row->counterparty_id : 0;
            if ($cpId !== 0) {
                $recent[$cpId] = $row;
            }
        }

        return $recent;
    }

    /**
     * @return array<int, array<string, array<string, int>>>
     */
    private function monthlyBucketsByCounterparty(User $user, string $cutoffDate): array
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('counterparty_id')
            ->where('posted_at', '>=', $cutoffDate)
            ->groupBy('counterparty_id', 'ym', 'settled_currency')
            ->selectRaw("counterparty_id, strftime('%Y-%m', posted_at) as ym, settled_currency, COALESCE(SUM(settled_amount_minor), 0) as total")
            ->get();

        $monthly = [];
        foreach ($rows as $row) {
            $cpId = is_numeric($row->counterparty_id ?? null) ? (int) $row->counterparty_id : 0;
            $ym = is_string($row->ym ?? null) ? $row->ym : '';
            $currency = is_string($row->settled_currency ?? null) ? $row->settled_currency : '';
            if ($cpId === 0 || $ym === '' || $currency === '') {
                continue;
            }

            $monthly[$cpId][$ym][$currency] = is_numeric($row->total ?? null) ? (int) $row->total : 0;
        }

        return $monthly;
    }

    private function recentLineFrom(?stdClass $recent, int $userId): ?string
    {
        if ($recent === null) {
            return null;
        }

        $postedAt = $recent->posted_at ?? null;
        $description = $recent->description ?? null;
        $counterpartyName = $recent->counterparty_name ?? null;
        if (is_string($description) && $description !== '') {
            $description = $this->codec->decryptValue('transactions', 'description', $description, $userId, $this->session)['value'];
        }
        if (is_string($counterpartyName) && $counterpartyName !== '') {
            $counterpartyName = $this->codec->decryptValue('transactions', 'counterparty_name', $counterpartyName, $userId, $this->session)['value'];
        }

        // Fmt, not a substr of the ISO column: this line and the card's date
        // field then share one locale pattern instead of agreeing by luck.
        $date = is_string($postedAt) && $postedAt !== ''
            ? Fmt::shortDate($postedAt)
            : '';
        $label = match (true) {
            is_string($description) && $description !== '' => $description,
            is_string($counterpartyName) => $counterpartyName,
            default => '',
        };
        $recentLine = trim($date.' · '.$label, ' ·');

        return $recentLine === '' ? null : $recentLine;
    }

    /**
     * @return array<string, int> keyed by CounterpartyTypeFilter value
     */
    public function countsByType(User $user): array
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->get();

        $counts = array_fill_keys(
            array_map(
                static fn (CounterpartyTypeFilter $filter): string => $filter->value,
                CounterpartyTypeFilter::cases(),
            ),
            0,
        );

        foreach ($rows as $row) {
            $type = CounterpartyType::tryFrom(is_string($row->type ?? null) ? $row->type : '');
            $cnt = is_numeric($row->cnt ?? null) ? (int) $row->cnt : 0;
            $counts[CounterpartyTypeFilter::All->value] += $cnt;

            if ($type !== null) {
                $counts[CounterpartyTypeFilter::forColumnValue($type)->value] = $cnt;
            }
        }

        return $counts;
    }
}
