<?php

declare(strict_types=1);

namespace Modules\Goals\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DemoNames;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Goals\Public\Services\GoalWriter;

// Savings goals for the demo install. Three draw their progress from a demo
// pot (DemoPotsSeeder runs after this one and resolves goals by name); the
// fourth has no pot and shows the other source — the demo credits the user
// explicitly attributed to it.
final class DemoGoalsSeeder
{
    // monthsOut drives target_date relative to the seed run so the demo never
    // ships a goal whose deadline has already passed, and `complete` exercises
    // the finished-goal rendering path. `fundedBy` picks the attributed credits
    // by (type, amount) — description is ciphertext at rest under encryption.
    /** @var list<array{nameKey: string, amount: string, monthsOut: int, startedDaysAgo: int, complete: bool, fundedBy: ?array{type: string, amountMinor: int}}> */
    private const GOALS = [
        ['nameKey' => 'goal_emergency_fund', 'amount' => '5000,00', 'monthsOut' => 18, 'startedDaysAgo' => 80, 'complete' => false, 'fundedBy' => null],
        ['nameKey' => 'goal_japan_trip', 'amount' => '4500,00', 'monthsOut' => 14, 'startedDaysAgo' => 62, 'complete' => false, 'fundedBy' => null],
        ['nameKey' => 'goal_replace_laptop', 'amount' => '1800,00', 'monthsOut' => 8, 'startedDaysAgo' => 45, 'complete' => false, 'fundedBy' => null],
        ['nameKey' => 'goal_winter_tyres', 'amount' => '600,00', 'monthsOut' => 3, 'startedDaysAgo' => 30, 'complete' => true, 'fundedBy' => ['type' => 'transfer_in', 'amountMinor' => 10000]],
    ];

    public function __construct(
        private readonly GoalWriter $writer,
        private readonly GoalContributionWriter $contributions,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::GOALS as $row) {
                $this->upsertGoal($primary, $row);
            }
        }

        return Goal::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{nameKey: string, amount: string, monthsOut: int, startedDaysAgo: int, complete: bool, fundedBy: ?array{type: string, amountMinor: int}}  $row
     */
    private function upsertGoal(User $user, array $row): void
    {
        // Demo content is the first thing a new install shows, so it is named
        // in the interface language rather than in English.
        $name = Lang::get('core::demo.'.$row['nameKey']);

        // Matched against every locale's rendering, not just today's: the
        // dedupe key is a translated string, so a re-seed under a different
        // APP_LOCALE otherwise made a second copy of every goal.
        $existing = Goal::query()
            ->where('user_id', $user->id)
            ->whereIn('name', DemoNames::everyRendering($row['nameKey']))
            ->first();

        if ($existing !== null) {
            return;
        }

        $goal = $this->writer->save(
            $user,
            $name,
            $row['amount'],
            CarbonImmutable::today()->addMonths($row['monthsOut'])->toDateString(),
        );

        // The writer stamps start_date as today, which leaves every seeded
        // goal inside the projector's minimum observation window and stuck
        // on "building a projection". Backdating gives the run-rate a real
        // window of demo contributions to measure.
        $goal->start_date = CarbonImmutable::today()->subDays($row['startedDaysAgo']);
        $goal->save();

        if ($row['fundedBy'] !== null) {
            $this->attributeCredits($user, $goal->id, $row['fundedBy']);
        }

        if ($row['complete']) {
            $this->writer->markComplete($user, $goal->id);
        }
    }

    /**
     * @param  array{type: string, amountMinor: int}  $criteria
     */
    private function attributeCredits(User $user, int $goalId, array $criteria): void
    {
        $transactionIds = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('type', $criteria['type'])
            ->where('amount_minor', $criteria['amountMinor'])
            ->orderBy('posted_at')
            ->pluck('id')
            ->all();

        foreach ($transactionIds as $transactionId) {
            if (is_numeric($transactionId)) {
                $this->contributions->attribute($user, $goalId, (int) $transactionId);
            }
        }
    }
}
