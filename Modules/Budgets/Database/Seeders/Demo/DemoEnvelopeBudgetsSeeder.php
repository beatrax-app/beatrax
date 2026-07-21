<?php

declare(strict_types=1);

namespace Modules\Budgets\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../../.docs/features/budgets/architecture.md
 */
final class DemoEnvelopeBudgetsSeeder
{
    // Deliberately leaves several spending categories (cash withdrawal, car
    // maintenance, memberships, donations, fees...) unbudgeted so the grid
    // shows a genuine mix of funded and overspent envelopes plus a positive
    // "Ready to assign" pool, rather than an all-red grid.
    /**
     * @var array<string, int>
     */
    private const BUDGETS = [
        'housing-rent' => 120000,
        'housing-utilities' => 15000,
        'housing-internet' => 6000,
        'groceries' => 30000,
        'eating-out' => 12000,
        'transport-fuel' => 8000,
        'transport-public' => 5000,
        'insurance-health' => 14000,
        'subscriptions-streaming' => 3000,
        'subscriptions-cloud' => 10000,
        'personal-care' => 4000,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $userMap  username => User
     */
    public function run(array $userMap): int
    {
        $connection = $this->db->connection();
        $now = $this->clock->now();

        /** @var array<string, int> $categoryIdBySlug */
        $categoryIdBySlug = $connection->table('categories')
            ->whereNull('user_id')
            ->where('kind', 'expense')
            ->pluck('id', 'slug')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $seeded = 0;

        foreach ($userMap as $user) {
            $connection->table('users')
                ->where('id', $user->id)
                ->whereNull('envelope_activated_at')
                ->update(['envelope_activated_at' => $now->toDateTimeString()]);

            $periodStart = $this->currentPeriodStart($now, (int) $user->period_start_day);

            foreach (self::BUDGETS as $slug => $minor) {
                $categoryId = $categoryIdBySlug[$slug] ?? 0;
                if ($categoryId === 0) {
                    continue;
                }

                /** @var \stdClass|null $existing */
                $existing = $connection->table('envelope_assignments')
                    ->where('user_id', $user->id)
                    ->where('category_id', $categoryId)
                    ->where('period_start', $periodStart)
                    ->first(['id']);

                if ($existing === null) {
                    $connection->table('envelope_assignments')->insert([
                        'user_id' => $user->id,
                        'category_id' => $categoryId,
                        'period_start' => $periodStart,
                        'assigned_minor' => $minor,
                        'currency' => 'EUR',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $connection->table('envelope_assignments')
                        ->where('id', $existing->id)
                        ->update([
                            'assigned_minor' => $minor,
                            'currency' => 'EUR',
                            'updated_at' => $now,
                        ]);
                }

                $seeded++;
            }
        }

        return $seeded;
    }

    // Replicates PeriodQuery::containing() exactly (that service resolves
    // the period from the authenticated user and so cannot be called
    // per-user from a seeder) so the seeded period_start matches the
    // period the live grid folds.
    private function currentPeriodStart(CarbonImmutable $now, int $periodStartDay): string
    {
        $startDay = max(1, min(28, $periodStartDay));
        $candidate = $now->setDay($startDay)->startOfDay();
        $start = $now->day >= $startDay ? $candidate : $candidate->subMonthNoOverflow();

        return $start->toDateString();
    }
}
