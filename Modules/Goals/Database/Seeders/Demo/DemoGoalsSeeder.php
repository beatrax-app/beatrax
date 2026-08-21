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

// Three goals draw progress from a demo pot; the fourth deliberately has none,
// so the demo also shows the other source, explicitly attributed credits.
final class DemoGoalsSeeder
{
    // monthsOut is relative to the seed run, so a demo install never ships a goal
    // whose deadline has already passed. fundedBy matches credits on (type,
    // amount) because description is ciphertext at rest.
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
        $name = Lang::get('core::demo.'.$row['nameKey']);

        // Every locale's rendering, not just today's: the dedupe key is a
        // translated string, so a re-seed under another APP_LOCALE duplicated it.
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

        // The writer stamps start_date as today, which parks every seeded goal
        // inside the projector's minimum observation window.
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
