<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Read-side query powering `/counterparties` — the type-filtered,
 * sortable index of all counterparties the authenticated user has.
 *
 * Each row carries the headline data the card / list-row needs to
 * render (12-month total, per-month average, 12-bar sparkline, recent
 * activity line); aggregates are computed via SQL GROUP BY + SUM so
 * the per-render cost stays bounded regardless of how much history
 * the user has imported.
 *
 * Cross-user posture: every read carries an explicit
 * `where('user_id', $user->id)` filter at the raw query-builder
 * boundary. The BelongsToUser global scope on the Counterparty model
 * is a secondary guard that only fires under HTTP-bound Eloquent
 * surfaces; the read queries here use the query builder directly so
 * the explicit filter is the load-bearing scope.
 *
 * Personal-IBAN privacy default: rows of type `personal` carry the
 * underlying counterparty's display name as `displayName`, but the
 * IBAN never leaks into the index DTO under any code path — the
 * `iban` field on `CounterpartyIndexRow` does not exist.
 */
final readonly class CounterpartyIndexQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * Returns one `CounterpartyIndexRow` per counterparty the user has,
     * optionally filtered by type and sorted by absolute 12-month
     * total descending.
     *
     * `typeFilter` is one of `all` | `merchant` | `personal` | `bank` |
     * `government` | `self` | `unknown`. The `self` filter maps to the
     * resolver's internal `self_account` type so the chip label and
     * the type column read consistently.
     *
     * @return Collection<int, CounterpartyIndexRow>
     */
    public function forUser(User $user, string $typeFilter = 'all'): Collection
    {
        $resolvedType = $typeFilter === 'self' ? 'self_account' : $typeFilter;

        $connection = $this->db->connection();

        $query = $connection->table('counterparties')->where('user_id', $user->id);
        if ($resolvedType !== 'all') {
            $query = $query->where('type', $resolvedType);
        }

        /** @var iterable<stdClass> $cpRows */
        $cpRows = $query->orderBy('display_name')->get(['id', 'slug', 'display_name', 'type']);

        $cutoffDate = $this->clock->now()->subYear()->toDateString();
        $userId = $user->id;

        /** @var list<CounterpartyIndexRow> $result */
        $result = [];
        foreach ($cpRows as $cpRow) {
            $cpId = is_numeric($cpRow->id ?? null) ? (int) $cpRow->id : 0;
            if ($cpId === 0) {
                continue;
            }
            $slug = is_string($cpRow->slug ?? null) ? $cpRow->slug : '';
            $storedDisplayName = is_string($cpRow->display_name ?? null) ? $cpRow->display_name : '';
            // CRYPT-01 (D-02b) read-side decrypt — pass-through no-op when
            // encryption is not enabled for this user.
            $displayName = $storedDisplayName === ''
                ? ''
                : $this->codec->decryptValue('counterparties', 'display_name', $storedDisplayName, $userId, $this->session)['value'];
            $type = is_string($cpRow->type ?? null) ? $cpRow->type : 'unknown';

            // 12-month total + transaction count via a single aggregate
            // query — the (user_id, counterparty_id) composite index
            // shipped in 17-05a covers this exactly.
            /** @var stdClass|null $totals */
            $totals = $connection->table('transactions')
                ->where('user_id', $user->id)
                ->where('counterparty_id', $cpId)
                ->where('posted_at', '>=', $cutoffDate)
                ->selectRaw('COALESCE(SUM(amount_minor), 0) as total, COUNT(*) as cnt')
                ->first();

            $total = $totals !== null && is_numeric($totals->total ?? null) ? (int) $totals->total : 0;
            $count = $totals !== null && is_numeric($totals->cnt ?? null) ? (int) $totals->cnt : 0;
            $avg = $count > 0 ? (int) round($total / 12) : 0;

            // Most-recent transaction's posted date + short description
            // — feeds the card's recent-line strip.
            /** @var stdClass|null $recent */
            $recent = $connection->table('transactions')
                ->where('user_id', $user->id)
                ->where('counterparty_id', $cpId)
                ->orderByDesc('posted_at')
                ->orderByDesc('id')
                ->first(['posted_at', 'description', 'counterparty_name']);

            $recentLine = null;
            if ($recent !== null) {
                $postedAt = $recent->posted_at ?? null;
                $description = $recent->description ?? null;
                $counterpartyName = $recent->counterparty_name ?? null;
                // CRYPT-01 (D-02b) read-side decrypt — pass-through no-op
                // when encryption is not enabled for this user.
                if (is_string($description) && $description !== '') {
                    $description = $this->codec->decryptValue('transactions', 'description', $description, $userId, $this->session)['value'];
                }
                if (is_string($counterpartyName) && $counterpartyName !== '') {
                    $counterpartyName = $this->codec->decryptValue('transactions', 'counterparty_name', $counterpartyName, $userId, $this->session)['value'];
                }
                $date = is_string($postedAt) ? substr($postedAt, 0, 10) : '';
                $label = is_string($description) && $description !== ''
                    ? $description
                    : (is_string($counterpartyName) ? $counterpartyName : '');
                $recentLine = trim($date.' · '.$label, ' ·');
                if ($recentLine === '') {
                    $recentLine = null;
                }
            }

            $result[] = new CounterpartyIndexRow(
                id: $cpId,
                slug: $slug,
                displayName: $displayName,
                type: $type,
                total12mMinor: $total,
                avgPerMonthMinor: $avg,
                recentLine: $recentLine,
                sparkline: $this->sparklineFor($user, $cpId, $cutoffDate),
            );
        }

        // Sort by 12-month absolute total descending; tie-break by name asc.
        usort($result, static function (CounterpartyIndexRow $a, CounterpartyIndexRow $b): int {
            $order = abs($b->total12mMinor) <=> abs($a->total12mMinor);

            return $order !== 0 ? $order : strcmp($a->displayName, $b->displayName);
        });

        return new Collection($result);
    }

    /**
     * Returns the per-type chip counts for the user's index — the
     * shape feeding the `<x-counterparties::filter-chips>` row at the
     * top of `/counterparties`. The `all` key is the unfiltered total.
     *
     * @return array<string, int>
     */
    public function countsByType(User $user): array
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('counterparties')
            ->where('user_id', $user->id)
            ->selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->get();

        $counts = [
            'all' => 0,
            'merchant' => 0,
            'personal' => 0,
            'bank' => 0,
            'government' => 0,
            'self' => 0,
            'unknown' => 0,
        ];

        foreach ($rows as $row) {
            $type = is_string($row->type ?? null) ? $row->type : '';
            $cnt = is_numeric($row->cnt ?? null) ? (int) $row->cnt : 0;
            $counts['all'] += $cnt;

            // Map the resolver's `self_account` storage type onto the
            // `self` chip key the UI uses.
            $chipKey = $type === 'self_account' ? 'self' : $type;
            if (array_key_exists($chipKey, $counts)) {
                $counts[$chipKey] = $cnt;
            }
        }

        return $counts;
    }

    /**
     * 12-bar monthly sparkline of signed minor-unit totals. Builds the
     * array oldest → newest so the card's last-bar emphasis (per
     * UI-SPEC) flags the current month.
     *
     * @return array<int, int>
     */
    private function sparklineFor(User $user, int $counterpartyId, string $cutoffDate): array
    {
        $connection = $this->db->connection();

        /** @var iterable<stdClass> $rows */
        $rows = $connection->table('transactions')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $counterpartyId)
            ->where('posted_at', '>=', $cutoffDate)
            ->selectRaw("strftime('%Y-%m', posted_at) as ym, COALESCE(SUM(amount_minor), 0) as total")
            ->groupBy('ym')
            ->get();

        $perMonth = [];
        foreach ($rows as $row) {
            $ym = is_string($row->ym ?? null) ? $row->ym : '';
            $total = is_numeric($row->total ?? null) ? (int) $row->total : 0;
            if ($ym !== '') {
                $perMonth[$ym] = $total;
            }
        }

        $sparkline = [];
        $cursor = $this->clock->now()->subMonths(11)->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $sparkline[] = $perMonth[$key] ?? 0;
            $cursor = $cursor->addMonth();
        }

        return $sparkline;
    }
}
