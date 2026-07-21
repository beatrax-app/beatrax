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
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
final readonly class CounterpartyIndexQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    // typeFilter is one of all|merchant|personal|bank|government|self|
    // unknown; `self` maps onto the resolver's internal self_account type
    // so the chip label and the type column read consistently.
    /**
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

        // Not ordered by the ciphertext display_name column — meaningless
        // once encryption is enabled. orderBy('id') just makes iteration
        // deterministic; the real user-facing order is the post-decrypt
        // usort() below (12-month total desc, then name asc).
        /** @var iterable<stdClass> $cpRows */
        $cpRows = $query->orderBy('id')->get(['id', 'slug', 'display_name', 'type']);

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
            // Read-side decrypt — a pass-through no-op when encryption is
            // not enabled for this user.
            $displayName = $storedDisplayName === ''
                ? ''
                : $this->codec->decryptValue('counterparties', 'display_name', $storedDisplayName, $userId, $this->session)['value'];
            $type = is_string($cpRow->type ?? null) ? $cpRow->type : 'unknown';

            // 12-month total + transaction count via a single aggregate
            // query, covered by the (user_id, counterparty_id) composite index.
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
                // Read-side decrypt — a pass-through no-op when encryption
                // is not enabled for this user.
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

        // Sort by 12-month absolute total descending, tie-broken by name
        // ascending.
        usort($result, static function (CounterpartyIndexRow $a, CounterpartyIndexRow $b): int {
            $order = abs($b->total12mMinor) <=> abs($a->total12mMinor);

            return $order !== 0 ? $order : strcmp($a->displayName, $b->displayName);
        });

        return new Collection($result);
    }

    // Feeds the <x-counterparties::filter-chips> row atop /counterparties;
    // `all` is the unfiltered total.
    /**
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

    // Builds oldest -> newest so the card's last-bar emphasis flags the
    // current month.
    /**
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
