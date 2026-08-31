<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Budgets\Public\Dto\EnvelopeMoveRow;
use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Ledger\Public\ValueObjects\Money;

// The move history an envelope shows under its own row. There is no stored
// balance column anywhere: the grid's net_moved term is folded out of these
// same rows by CarryoverQuery, fresh on every read.
final readonly class EnvelopeBalanceQuery
{
    use CoercesScalars;

    // A row records the currency it was written in, and the fold nets those
    // rows into the reader's own before it prints them. A history line left in
    // the stored units said the envelope had received EUR 500.00 beside a
    // moved column reading EUR 440.18, off one rate the fold had applied.
    public function __construct(
        private DatabaseManager $db,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    /**
     * @return list<EnvelopeMoveRow>
     */
    public function recentMovesFor(int $userId, int $categoryId, Period $period, int $limit = 10): array
    {
        $moves = $this->db->connection()
            ->table('envelope_moves as m')
            ->join('categories as c', 'c.id', '=', 'm.counterpart_category_id');

        $rows = CategoryPathName::joinParent($moves, $userId, 'c', 'cp')
            ->where('m.user_id', $userId)
            ->where('m.category_id', $categoryId)
            ->where('m.period_start', $period->start->toDateString())
            ->where(static function (QueryBuilder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->limit($limit)
            ->get([
                'm.id',
                'm.kind',
                'm.amount_minor',
                'm.currency',
                'm.counterpart_category_id',
                ...CategoryPathName::columns('c', 'cp', 'counterpart_category'),
                'm.memo',
                'm.created_at',
            ]);

        $target = $this->baseCurrency->code();
        $rates = $this->ratesFor($rows->all(), $target);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapMoveRow($row, $target, $rates);
        }

        return $result;
    }

    // Batched variant of recentMovesFor(): one query for many categories, so
    // a render does not go N+1 across the envelopes on the page.
    /**
     * @param  list<int>  $categoryIds
     * @return array<int, list<EnvelopeMoveRow>> category_id => recent moves
     */
    public function recentMovesForCategories(int $userId, array $categoryIds, Period $period, int $limit = 10): array
    {
        $buckets = [];
        foreach ($categoryIds as $categoryId) {
            $buckets[$categoryId] = [];
        }

        if ($categoryIds === []) {
            return $buckets;
        }

        $moves = $this->db->connection()
            ->table('envelope_moves as m')
            ->join('categories as c', 'c.id', '=', 'm.counterpart_category_id');

        $rows = CategoryPathName::joinParent($moves, $userId, 'c', 'cp')
            ->where('m.user_id', $userId)
            ->whereIn('m.category_id', $categoryIds)
            ->where('m.period_start', $period->start->toDateString())
            ->where(static function (QueryBuilder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->get([
                'm.id',
                'm.category_id',
                'm.kind',
                'm.amount_minor',
                'm.currency',
                'm.counterpart_category_id',
                ...CategoryPathName::columns('c', 'cp', 'counterpart_category'),
                'm.memo',
                'm.created_at',
            ]);

        $target = $this->baseCurrency->code();
        $rates = $this->ratesFor($rows->all(), $target);

        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            if (! array_key_exists($categoryId, $buckets) || count($buckets[$categoryId]) >= $limit) {
                continue;
            }

            $buckets[$categoryId][] = $this->mapMoveRow($row, $target, $rates);
        }

        return $buckets;
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return array<string, string>
     */
    private function ratesFor(array $rows, string $target): array
    {
        return $this->fx->ratesTo(
            array_values(array_map(static fn (\stdClass $row): string => self::toString($row->currency), $rows)),
            $target,
        );
    }

    // A row the rate table cannot price travels in the currency it was written
    // in, the same rule copyFromPeriod() applies: relabelling it as the
    // reader's would print a number that was never that many euros. A kind
    // this build cannot name travels too, as a null the screen has copy for.
    /**
     * @param  array<string, string>  $rates
     *
     * @link ../../../../.docs/features/sync/a-peer-may-be-on-a-newer-version.md
     */
    private function mapMoveRow(\stdClass $row, string $target, array $rates): EnvelopeMoveRow
    {
        $stored = self::toInt($row->amount_minor);
        $storedCurrency = self::toString($row->currency);
        $money = Money::tryOfMinor($stored, $storedCurrency);
        $converted = $money === null ? null : $this->fx->convert($money, $target, $rates);

        return new EnvelopeMoveRow(
            id: self::toInt($row->id),
            kind: EnvelopeMoveKind::tryFrom(self::toString($row->kind)),
            amountMinor: $converted === null ? $stored : $converted->toMinor(),
            currency: $converted === null ? $storedCurrency : $target,
            counterpartCategoryId: self::toInt($row->counterpart_category_id),
            counterpartCategoryName: CategoryPathName::fromRow($row, 'counterpart_category') ?? '',
            memo: is_string($row->memo) ? $row->memo : null,
            createdAt: $this->formatCreatedAt($row->created_at ?? null),
        );
    }

    private function formatCreatedAt(mixed $createdAtRaw): string
    {
        return SafeDate::parseOrNull(self::toString($createdAtRaw))?->format('Y-m-d H:i') ?? '';
    }
}
