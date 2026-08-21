<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Fmt;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class CounterpartyIndexQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * @param  string  $typeFilter  one of all|merchant|personal|bank|government|self|unknown
     * @return Collection<int, CounterpartyIndexRow>
     */
    public function forUser(User $user, string $typeFilter = 'all'): Collection
    {
        $resolvedType = $typeFilter === 'self' ? CounterpartyType::SelfAccount->value : $typeFilter;

        $query = $this->db->connection()->table('counterparties')->where('user_id', $user->id);
        if ($resolvedType !== 'all') {
            $query = $query->where('type', $resolvedType);
        }

        // Never order by display_name here: it is ciphertext once encryption
        // is on. orderBy('id') only makes iteration deterministic — the
        // user-facing order is the post-decrypt usort() below.
        /** @var iterable<stdClass> $cpRows */
        $cpRows = $query->orderBy('id')->get(['id', 'slug', 'display_name', 'type']);

        $cutoffDate = $this->clock->now()->subYear()->toDateString();

        /** @var list<CounterpartyIndexRow> $result */
        $result = [];
        foreach ($cpRows as $cpRow) {
            $row = $this->buildRow($cpRow, $user, $cutoffDate);
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

    private function buildRow(stdClass $cpRow, User $user, string $cutoffDate): ?CounterpartyIndexRow
    {
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
        $type = is_string($cpRow->type ?? null) ? $cpRow->type : CounterpartyType::Unknown->value;

        /** @var stdClass|null $totals */
        $totals = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_id', $cpId)
            ->where('posted_at', '>=', $cutoffDate)
            ->selectRaw('COALESCE(SUM(amount_minor), 0) as total, COUNT(*) as cnt')
            ->first();

        $total = $totals !== null && is_numeric($totals->total ?? null) ? (int) $totals->total : 0;
        $count = $totals !== null && is_numeric($totals->cnt ?? null) ? (int) $totals->cnt : 0;
        $avg = $count > 0 ? (int) round($total / 12) : 0;

        return new CounterpartyIndexRow(
            id: $cpId,
            slug: $slug,
            displayName: $displayName,
            type: $type,
            total12mMinor: $total,
            avgPerMonthMinor: $avg,
            recentLine: $this->recentLineFor($user, $cpId),
            sparkline: $this->sparklineFor($user, $cpId, $cutoffDate),
        );
    }

    private function recentLineFor(User $user, int $counterpartyId): ?string
    {
        $userId = $user->id;

        /** @var stdClass|null $recent */
        $recent = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_id', $counterpartyId)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->first(['posted_at', 'description', 'counterparty_name']);

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

            $chipKey = $type === CounterpartyType::SelfAccount->value ? 'self' : $type;
            if (array_key_exists($chipKey, $counts)) {
                $counts[$chipKey] = $cnt;
            }
        }

        return $counts;
    }

    /**
     * @return array<int, int> twelve monthly totals, oldest first — the last is the current month
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
