<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Budgets\Internal\Rekey\PeriodShift;
use Modules\Budgets\Internal\Support\EnvelopeMoveId;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\IdReadBack;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;

// CarryoverQuery looks envelope rows up by their literal period_start, and
// period_start_day moves every boundary at once: without this pass the rows
// stay on disk matching no period the fold walks, and the plan reads as zero.
// Propagated as delete + create because period_start is create-only in sync.
/**
 * @link ../../../../.docs/features/budgets/moving-the-budget-month.md
 */
final readonly class EnvelopePeriodRekeyer
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private Clock $clock,
        private PeriodQuery $periods,
        private CurrentUser $currentUser,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    public function rekeyToCurrentPeriods(int $previousStartDay): void
    {
        $shift = $this->shiftFrom($previousStartDay);
        $pending = [];

        $this->db->connection()->transaction(function () use ($shift, &$pending): void {
            $pending = [
                ...$this->rekeyAssignments($shift),
                ...$this->rekeyMoves($shift),
            ];
        });

        foreach ($pending as $event) {
            $this->events->dispatch($event);
        }
    }

    // The genesis the fold starts at moves with the day as well, so both ends
    // of the shift are read here once and handed to every row.
    private function shiftFrom(int $previousStartDay): PeriodShift
    {
        $raw = $this->db->connection()
            ->table('users')
            ->where('id', $this->currentUser->user()->id)
            ->value('envelope_activated_at');

        $activatedAt = is_string($raw) ? SafeDate::parseOrNull($raw) : null;
        $now = $this->clock->now();

        return new PeriodShift(
            previousStartDay: $previousStartDay,
            anchorOld: $this->periods->containingForDay($previousStartDay, $now)->start,
            anchorNew: $this->periods->containing($now)->start,
            genesisOld: $activatedAt === null ? null : $this->periods->containingForDay($previousStartDay, $activatedAt)->start,
            genesisNew: $activatedAt === null ? null : $this->periods->containing($activatedAt)->start,
        );
    }

    /**
     * @return list<EnvelopeAssignmentMutated>
     *
     * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
     */
    private function rekeyAssignments(PeriodShift $shift): array
    {
        $userId = $this->currentUser->user()->id;
        $connection = $this->db->connection();

        $rows = $connection->table('envelope_assignments')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['id', 'category_id', 'period_start', 'assigned_minor', 'currency', 'created_at']);

        $targets = $this->targets($rows, $shift);
        if ($targets === []) {
            return [];
        }

        // Two old keys landing on one period would break the (user, category,
        // period_start) UNIQUE, so the surviving row carries their sum. The
        // grouping covers rows that did not move as well, because one that
        // stayed put may be the row a moved one now collides with.
        $buckets = [];
        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            $key = $targets[self::toInt($row->id)] ?? self::toString($row->period_start);
            $bucket = $categoryId.'|'.$key;
            $buckets[$bucket] ??= [
                'category_id' => $categoryId,
                'period_start' => $key,
                'by_currency' => [],
                'currency' => self::toString($row->currency),
                'created_at' => $row->created_at,
            ];
            $rowCurrency = self::toString($row->currency);
            $buckets[$bucket]['by_currency'][$rowCurrency] =
                ($buckets[$bucket]['by_currency'][$rowCurrency] ?? 0) + self::toInt($row->assigned_minor);
        }

        $totalled = $this->totalled($buckets);

        $events = [];
        foreach ($rows as $row) {
            $events[] = new EnvelopeAssignmentMutated(
                assignmentId: self::toInt($row->id),
                userId: $userId,
                mutationType: 'delete',
            );
        }
        $connection->table('envelope_assignments')->where('user_id', $userId)->delete();

        $now = $this->clock->now()->toDateTimeString();
        foreach ($totalled as $bucket) {
            $connection->table('envelope_assignments')->insert([
                'user_id' => $userId,
                'category_id' => $bucket['category_id'],
                'period_start' => $bucket['period_start'],
                'assigned_minor' => $bucket['assigned_minor'],
                'currency' => $bucket['currency'],
                'created_at' => $bucket['created_at'] ?? $now,
                'updated_at' => $now,
            ]);

            // The id is read back by the UNIQUE named above, never taken from
            // insertGetId(): lastInsertId() is per connection, and the sidebar's
            // badge listener writes a `cache` row from inside this INSERT's own
            // event. A wrong id here is a sync op against a stranger.
            $id = IdReadBack::of($connection, 'envelope_assignments', [
                'user_id' => $userId,
                'category_id' => $bucket['category_id'],
                'period_start' => $bucket['period_start'],
            ]);

            $events[] = new EnvelopeAssignmentMutated(
                assignmentId: $id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $userId,
                    'category_id' => $bucket['category_id'],
                    'period_start' => $bucket['period_start'],
                    'assigned_minor' => $bucket['assigned_minor'],
                    'currency' => $bucket['currency'],
                ],
            );
        }

        return $events;
    }

    // Two merging months need not share a currency, and adding their minor units
    // invented the difference: EUR 100 plus USD 100 came out one EUR 200
    // envelope. A bucket the rate table can price whole is converted first; one
    // it cannot is left summed, rather than losing the part that has no rate.
    /**
     * @param  array<string, array{category_id: int, period_start: string, by_currency: array<string, int>, currency: string, created_at: mixed}>  $buckets
     * @return list<array{category_id: int, period_start: string, assigned_minor: int, currency: string, created_at: mixed}>
     */
    private function totalled(array $buckets): array
    {
        $baseCurrency = $this->baseCurrency->forUser($this->currentUser->user());

        $totalled = [];
        foreach ($buckets as $bucket) {
            $byCurrency = $bucket['by_currency'];
            $converted = count($byCurrency) > 1 ? $this->fx->of($byCurrency, $baseCurrency) : null;
            $whole = $converted !== null && $converted->unconverted === [] ? $converted : null;

            $totalled[] = [
                'category_id' => $bucket['category_id'],
                'period_start' => $bucket['period_start'],
                'assigned_minor' => $whole->minor ?? array_sum($byCurrency),
                'currency' => $whole === null ? $bucket['currency'] : $baseCurrency,
                'created_at' => $bucket['created_at'],
            ];
        }

        return $totalled;
    }

    /**
     * @return list<EnvelopeMoveMutated>
     */
    private function rekeyMoves(PeriodShift $shift): array
    {
        $userId = $this->currentUser->user()->id;
        $connection = $this->db->connection();

        $rows = $connection->table('envelope_moves')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get([
                'id',
                'category_id',
                'counterpart_category_id',
                'period_start',
                'amount_minor',
                'currency',
                'kind',
                'memo',
                'move_group_id',
                'created_at',
            ]);

        $targets = $this->targets($rows, $shift);
        if ($targets === []) {
            return [];
        }

        $events = [];
        $now = $this->clock->now()->toDateTimeString();

        // Append-only ledger: each row is re-created one for one, so the two
        // rows of a move stay paired on their shared move_group_id.
        foreach ($rows as $row) {
            $key = $targets[self::toInt($row->id)] ?? null;
            if ($key === null) {
                continue;
            }

            $events[] = new EnvelopeMoveMutated(
                moveId: self::toInt($row->id),
                userId: $userId,
                mutationType: 'delete',
            );
            $connection->table('envelope_moves')->where('id', $row->id)->delete();

            $fields = [
                'user_id' => $userId,
                'category_id' => self::toInt($row->category_id),
                'counterpart_category_id' => self::toInt($row->counterpart_category_id),
                'period_start' => $key,
                'amount_minor' => self::toInt($row->amount_minor),
                'currency' => self::toString($row->currency),
                'kind' => self::toString($row->kind),
                // Inside $fields, not beside it in the insert: the row the
                // reader typed a memo on is the row the peer has to receive,
                // and a column named in only one of the two travels nowhere.
                'memo' => $row->memo,
                'move_group_id' => $row->move_group_id,
            ];

            // Derived, not minted: both devices rekey on their own, and two
            // autoincrements would hand one id to two unrelated rows. The
            // stored spelling goes in raw because `kind` is synced and
            // unconstrained — a peer's third spelling has no case here.
            $id = EnvelopeMoveId::for(self::toString($row->move_group_id), $fields['kind'], $key);

            $connection->table('envelope_moves')->insert([
                'id' => $id,
                ...$fields,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);

            $events[] = new EnvelopeMoveMutated(
                moveId: $id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: $fields,
            );
        }

        return $events;
    }

    // Only the rows that actually move. A stored value that is no longer a
    // plain date has no period at all, so it is left where it is rather than
    // guessed at.
    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, string>
     */
    private function targets($rows, PeriodShift $shift): array
    {
        $moved = [];
        $resolved = [];

        foreach ($rows as $row) {
            $stored = self::toString($row->period_start);

            if (! array_key_exists($stored, $resolved)) {
                $resolved[$stored] = $this->targetFor($stored, $shift);
            }

            $target = $resolved[$stored];
            if ($target !== null && $target !== $stored) {
                $moved[self::toInt($row->id)] = $target;
            }
        }

        return $moved;
    }

    // A row keeps its distance in periods from the one the reader is in, so the
    // month they are living in stays the month their plan is on. Mapping the
    // old period's FIRST instant instead put it in the period BEFORE the new
    // one under every later start day, sliding the whole plan a month back.
    private function targetFor(string $stored, PeriodShift $shift): ?string
    {
        $oldPeriod = $this->periods->containingDateForDay($stored, $shift->previousStartDay);
        if ($oldPeriod === null) {
            return null;
        }

        $target = $shift->anchorNew->addMonthsNoOverflow(self::periodsBetween($shift->anchorOld, $oldPeriod->start));

        // The invariant, enforced rather than assumed: a row the fold could
        // read before the move still sits at or after genesis. Everything
        // earlier is filtered out of the walk, and month-back nav stops at
        // genesis, so a row that lands below it is gone for good.
        $genesisOld = $shift->genesisOld;
        $genesisNew = $shift->genesisNew;
        if ($genesisOld !== null && $genesisNew !== null
            && ! $oldPeriod->start->lessThan($genesisOld) && $target->lessThan($genesisNew)) {
            $target = $genesisNew;
        }

        return $target->toDateString();
    }

    // Both dates are period starts taken on the same start day, so they share a
    // day of month and the month delta is the period delta exactly.
    private static function periodsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ($to->year - $from->year) * 12 + ($to->month - $from->month);
    }
}
