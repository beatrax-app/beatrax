<?php

declare(strict_types=1);

namespace Modules\CashBook\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Public\Enums\Direction;

/**
 * @link ../../../../../.docs/features/ledger/what-the-demo-zero-decimal-account-has-to-show.md#cash-entry
 */
final class DemoCashEntriesSeeder
{
    // Every entry is an outflow. Filling a wallet is a transfer, and this action
    // writes only income and expense: booking the float as income would put it
    // in the envelope grid's ready-to-assign pool. The float is the account's
    // opening balance instead.
    /** @var list<array{daysAgo: int, amountMinor: int, counterparty: string, categorySlug: ?string}> */
    private const ENTRIES = [
        ['daysAgo' => 52, 'amountMinor' => 28000, 'counterparty' => 'Yamada Denki', 'categorySlug' => null],
        ['daysAgo' => 38, 'amountMinor' => 2300, 'counterparty' => 'Tokyo Metro', 'categorySlug' => 'transport-public'],
        ['daysAgo' => 24, 'amountMinor' => 12800, 'counterparty' => 'Kappabashi Dougu', 'categorySlug' => null],
        ['daysAgo' => 11, 'amountMinor' => 3400, 'counterparty' => 'Ichiran', 'categorySlug' => 'eating-out'],
        ['daysAgo' => 3, 'amountMinor' => 1500, 'counterparty' => 'Lawson', 'categorySlug' => 'groceries'],
    ];

    public function __construct(
        private readonly RecordManualTransaction $record,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;

        if ($primary !== null && ! $this->alreadySeeded($primary)) {
            $this->seedFor($primary, $this->clock->now()->startOfDay());
        }

        return $this->db->connection()->table('transactions')
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->where('source_format', SyntheticSourceFormat::Manual->value)
            ->count();
    }

    // The account is never named here: the action resolves the reader's single
    // cash account by kind, exactly as the page does, so a demo whose cash
    // account is the yen wallet books these in yen without being told to.
    private function seedFor(User $user, CarbonImmutable $today): void
    {
        foreach (self::ENTRIES as $entry) {
            ($this->record)(
                $user,
                Direction::Expense->value,
                $entry['amountMinor'],
                $today->subDays($entry['daysAgo']),
                $entry['counterparty'],
                $this->categoryId($entry['categorySlug']),
            );
        }
    }

    // The action writes a random `source_ref` per call, so nothing about a
    // second run would collide with the first. A re-seed without `--reset`
    // would otherwise add the same five entries again.
    private function alreadySeeded(User $user): bool
    {
        return $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('source_format', SyntheticSourceFormat::Manual->value)
            ->exists();
    }

    private function categoryId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        $id = $this->db->connection()->table('categories')
            ->whereNull('user_id')
            ->where('slug', $slug)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
