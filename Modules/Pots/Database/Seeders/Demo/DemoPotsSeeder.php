<?php

declare(strict_types=1);

namespace Modules\Pots\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Core\Public\Support\DemoNames;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// Runs after DemoGoalsSeeder — it resolves goals by name — and keeps the pot
// amounts under the seeded account balance so unallocated never goes negative.
/**
 * @link ../../../../../.docs/features/ledger/what-the-demo-zero-decimal-account-has-to-show.md#pots
 */
final class DemoPotsSeeder
{
    // Each amount is written at its own account's scale: '1250,00' is a euro
    // figure with a Dutch decimal mark, '168000' is a whole-yen one. The writer
    // parses at the pot's currency, so the two shapes are not interchangeable.
    /** @var list<array{nameKey: string, accountSlug: string, amount: string, goalKey: ?string}> */
    private const POTS = [
        ['nameKey' => 'pot_emergency_fund', 'accountSlug' => 'asn-demo-1', 'amount' => '1250,00', 'goalKey' => 'goal_emergency_fund'],
        ['nameKey' => 'pot_japan_trip', 'accountSlug' => 'asn-demo-1', 'amount' => '780,00', 'goalKey' => 'goal_japan_trip'],
        ['nameKey' => 'pot_new_laptop', 'accountSlug' => 'asn-demo-1', 'amount' => '450,00', 'goalKey' => 'goal_replace_laptop'],
        ['nameKey' => 'pot_annual_insurance', 'accountSlug' => 'asn-demo-1', 'amount' => '220,00', 'goalKey' => null],
        ['nameKey' => 'pot_ryokan_deposit', 'accountSlug' => 'jpy-cash-demo-1', 'amount' => '168000', 'goalKey' => 'goal_ryokan_stay'],
        ['nameKey' => 'pot_day_trips', 'accountSlug' => 'jpy-cash-demo-1', 'amount' => '13500', 'goalKey' => null],
    ];

    // A move between two pots carved out of one account balance, which is the
    // only pot operation that writes a pair. Nothing in the euro half of the
    // dataset exercised it, so the two transfer kinds rendered nowhere.
    /** @var list<array{fromKey: string, toKey: string, amount: string, memoKey: string}> */
    private const MOVES = [
        ['fromKey' => 'pot_day_trips', 'toKey' => 'pot_ryokan_deposit', 'amount' => '12000', 'memoKey' => 'pot_move_ryokan_deposit'],
    ];

    // Leaves the two yen pots a hundredfold apart: ¥150,000 beside ¥1,500. A
    // surface that divides the larger by a hundred prints the smaller one's
    // figure, which is a difference the reader can see rather than one they
    // have to already suspect.
    /** @var list<array{potKey: string, amount: string, memoKey: string}> */
    private const WITHDRAWALS = [
        ['potKey' => 'pot_ryokan_deposit', 'amount' => '30000', 'memoKey' => 'pot_withdraw_ryokan_deposit'],
    ];

    public function __construct(
        private readonly PotWriter $writer,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            $this->seedFor($primary);
        }

        return Pot::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    private function seedFor(User $user): void
    {
        $accountIds = $this->accountIdsBySlug($user);

        /** @var array<string, int> $created pots this run opened, keyed by name key */
        $created = [];

        foreach (self::POTS as $row) {
            $accountId = $accountIds[$row['accountSlug']] ?? null;
            if ($accountId === null) {
                continue;
            }

            $pot = $this->createPot($user, $row, $accountId);
            if ($pot !== null) {
                $created[$row['nameKey']] = $pot->id;
            }
        }

        // Only over pots this run opened. A second `demo:seed` without --reset
        // creates none, and replaying the movements against the pots already
        // standing would double every figure below the headline balance.
        foreach (self::MOVES as $move) {
            $from = $created[$move['fromKey']] ?? null;
            $to = $created[$move['toKey']] ?? null;

            if ($from !== null && $to !== null) {
                $this->writer->transfer($user, $from, $to, $move['amount'], Lang::get('core::demo.'.$move['memoKey']));
            }
        }

        foreach (self::WITHDRAWALS as $withdrawal) {
            $potId = $created[$withdrawal['potKey']] ?? null;

            if ($potId !== null) {
                $this->writer->withdraw($user, $potId, $withdrawal['amount'], Lang::get('core::demo.'.$withdrawal['memoKey']));
            }
        }
    }

    /**
     * @param  array{nameKey: string, accountSlug: string, amount: string, goalKey: ?string}  $row
     * @return Pot|null the pot this run opened, null when one already stood
     */
    private function createPot(User $user, array $row, int $accountId): ?Pot
    {
        $name = Lang::get('core::demo.'.$row['nameKey']);

        // Every locale's rendering, not just today's: the dedupe key is a
        // translated string, so a re-seed in another language duplicated it.
        $existing = Pot::query()
            ->where('user_id', $user->id)
            ->whereIn('name', DemoNames::everyRendering($row['nameKey']))
            ->first();

        if ($existing !== null) {
            return null;
        }

        $goalId = null;
        if ($row['goalKey'] !== null) {
            // The goal may have been seeded in another language; resolving by
            // today's rendering alone left the pot unlinked.
            $goalId = Goal::query()
                ->where('user_id', $user->id)
                ->whereIn('name', DemoNames::everyRendering($row['goalKey']))
                ->value('id');
            $goalId = is_numeric($goalId) ? (int) $goalId : null;
        }

        return $this->writer->save($user, $name, $row['amount'], $accountId, $goalId, null);
    }

    /**
     * @return array<string, int>
     */
    private function accountIdsBySlug(User $user): array
    {
        /** @var array<string, int> $ids */
        $ids = Account::query()
            ->where('user_id', $user->id)
            ->whereIn('slug', array_column(self::POTS, 'accountSlug'))
            ->pluck('id', 'slug')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }
}
