<?php

declare(strict_types=1);

namespace Modules\Budgets\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Services\BaseCurrency;

final class DemoEnvelopeBudgetsSeeder
{
    // Several spending categories are left unbudgeted on purpose, so the demo
    // grid shows funded and overspent envelopes and a positive "Ready to
    // assign" pool rather than a wall of red.
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
        private readonly BaseCurrency $baseCurrency,
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
            ->where('kind', CategoryKind::Expense->value)
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
                        'currency' => $this->baseCurrency->code(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $connection->table('envelope_assignments')
                        ->where('id', $existing->id)
                        ->update([
                            'assigned_minor' => $minor,
                            'currency' => $this->baseCurrency->code(),
                            'updated_at' => $now,
                        ]);
                }

                $seeded++;
            }
        }

        return $seeded;
    }

    // PeriodQuery::containing() resolves the period from the authenticated user,
    // so a seeder cannot call it per-user. The algorithm is replicated here so
    // the seeded period_start matches what the live grid folds.
    private function currentPeriodStart(CarbonImmutable $now, int $periodStartDay): string
    {
        $startDay = max(1, min(28, $periodStartDay));
        $candidate = $now->setDay($startDay)->startOfDay();
        $start = $now->day >= $startDay ? $candidate : $candidate->subMonthNoOverflow();

        return $start->toDateString();
    }
}
