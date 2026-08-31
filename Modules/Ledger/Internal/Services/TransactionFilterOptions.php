<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Ledger\Internal\Enums\DateRangePreset;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Support\CategoryPathName;

// What the filter chips can be set to, which is a different question from what
// the list is showing: both pickers span rows the current page never contains,
// so they are read from the tables rather than from the page.
final readonly class TransactionFilterOptions
{
    public function __construct(
        private DatabaseManager $db,
        private BaseCurrency $baseCurrency,
        private PeriodQuery $periods,
        private Clock $clock,
    ) {}

    // Resolved here rather than in the blade: the ranges turn on the reader's
    // own period_start_day, and a template that reached for it would be a
    // second place deciding what a month is.
    /** @return array<string, array{0: string, 1: string}> */
    public function dateRanges(User $user): array
    {
        return DateRangePreset::rangesFrom($this->periods, $this->clock->now(), $user->period_start_day);
    }

    /** @return list<array{id: int, name: string, currency: string}> */
    public function accounts(int $userId): array
    {
        $rows = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->all();

        // Defensive only: accounts.default_currency is char(3) NOT NULL with its
        // own default, so no row can reach it. Named rather than pinned, so the
        // day the column turns nullable this reads as the app's answer.
        $fallback = $this->baseCurrency->code();

        return array_values(array_map(static function (object $row) use ($fallback): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'currency' => is_string($row->default_currency) ? $row->default_currency : $fallback,
            ];
        }, $rows));
    }

    /** @return list<array{id: int, name: string}> */
    public function categories(int $userId): array
    {
        // Global (user_id IS NULL) OR user-owned: filtering on user_id alone
        // hid the chip on installs using only the seeded default tree.
        $rows = CategoryPathName::joinParent($this->db->connection()->table('categories as c'), $userId, 'c', 'cp')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->get(['c.id', ...CategoryPathName::columns('c', 'cp')])
            ->all();

        $paths = [];
        foreach ($rows as $row) {
            $paths[is_numeric($row->id) ? (int) $row->id : 0] = CategoryPathName::fromRow($row) ?? '';
        }

        $options = [];
        foreach (CategoryPathName::distinct($paths) as $id => $name) {
            $options[] = ['id' => $id, 'name' => $name];
        }

        // Sorted on what the reader sees; the stored English orders a
        // translated picker by a word that is not on screen.
        usort($options, static function (array $a, array $b): int {
            $byName = LocaleCollator::compare($a['name'], $b['name']);

            return $byName !== 0 ? $byName : $a['id'] <=> $b['id'];
        });

        return $options;
    }
}
