<?php

declare(strict_types=1);

namespace Modules\Goals\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Ledger\Models\Account;

// Savings goals for the demo install, anchored to the demo ASN current
// account so the projected finish date has real cash-flow behind it. The
// linked demo pots supply the contributed-so-far figure, so this seeder
// must run before DemoPotsSeeder, which resolves goals by name.
final class DemoGoalsSeeder
{
    // monthsOut drives target_date relative to the seed run so the demo
    // never ships a goal whose deadline has already passed. `complete`
    // exercises the finished-goal rendering path alongside active rows.
    /** @var list<array{name: string, amount: string, monthsOut: int, startedDaysAgo: int, complete: bool}> */
    private const GOALS = [
        ['name' => 'Emergency fund', 'amount' => '5000,00', 'monthsOut' => 18, 'startedDaysAgo' => 80, 'complete' => false],
        ['name' => 'Japan trip', 'amount' => '4500,00', 'monthsOut' => 14, 'startedDaysAgo' => 62, 'complete' => false],
        ['name' => 'Replace the laptop', 'amount' => '1800,00', 'monthsOut' => 8, 'startedDaysAgo' => 45, 'complete' => false],
        ['name' => 'Winter tyres', 'amount' => '600,00', 'monthsOut' => 3, 'startedDaysAgo' => 30, 'complete' => true],
    ];

    public function __construct(
        private readonly GoalWriter $writer,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            $accountId = $this->currentAccountId($primary);

            foreach (self::GOALS as $row) {
                $this->upsertGoal($primary, $row, $accountId);
            }
        }

        return Goal::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{name: string, amount: string, monthsOut: int, startedDaysAgo: int, complete: bool}  $row
     */
    private function upsertGoal(User $user, array $row, ?int $accountId): void
    {
        $existing = Goal::query()
            ->where('user_id', $user->id)
            ->where('name', $row['name'])
            ->first();

        if ($existing !== null) {
            return;
        }

        $goal = $this->writer->save(
            $user,
            $row['name'],
            $row['amount'],
            CarbonImmutable::today()->addMonths($row['monthsOut'])->toDateString(),
            $accountId,
        );

        // The writer stamps start_date as today, which leaves every seeded
        // goal inside the projector's minimum observation window and stuck
        // on "building a projection". Backdating gives the run-rate a real
        // window of demo transactions to measure.
        $goal->start_date = CarbonImmutable::today()->subDays($row['startedDaysAgo']);
        $goal->save();

        if ($row['complete']) {
            $this->writer->markComplete($user, $goal->id);
        }
    }

    private function currentAccountId(User $user): ?int
    {
        $account = Account::query()
            ->where('user_id', $user->id)
            ->where('kind', 'asn')
            ->orderBy('id')
            ->first();

        return $account?->id;
    }
}
