<?php

declare(strict_types=1);

namespace Modules\Budgets\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Activates zero-based envelope budgeting for the demo users and seeds a
 * realistic current-period assignment slate (Phase 13.2).
 *
 * Without this, a freshly-seeded demo user has `envelope_activated_at = NULL`
 * (the one-shot D-13 cutover migration only runs against users that existed
 * when it was applied, not demo users created afterwards), so `CarryoverQuery`
 * returns the D-12b all-zero result and `/budgets` renders an empty grid.
 *
 * Two responsibilities, both idempotent:
 *
 *  1. Genesis anchor — stamp `users.envelope_activated_at` for each demo user
 *     (only when NULL, so a re-run never moves the anchor). Mirrors
 *     `EnvelopeActivationService`'s claim, but scoped explicitly to the demo
 *     `$userMap` so a real user sharing the database is never touched. The demo
 *     dataset seeds no category-linked pots, so there is nothing to archive.
 *
 *  2. Assignments — seed the current period's `envelope_assignments` from the
 *     budget table below (upsert on the (user_id, category_id, period_start)
 *     UNIQUE tuple). The table deliberately leaves several spending categories
 *     (cash withdrawal, car maintenance, memberships, donations, fees…)
 *     UNbudgeted so the grid shows a genuine mix of funded (green) and
 *     overspent (red) envelopes plus a positive "Ready to assign" pool — the
 *     zero-based budgeting story in one screen, rather than an all-red grid.
 */
final class DemoEnvelopeBudgetsSeeder
{
    /**
     * Monthly envelope budget keyed by global expense-category slug (minor
     * units, EUR). Totals €2.270 — comfortably under the demo income so the
     * "Ready to assign" pool stays positive.
     *
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
     * Activate every demo user for envelope budgeting and seed the current
     * period's assignments.
     *
     * @param  array<string, User>  $userMap  username => User
     * @return int total envelope_assignments rows present after seeding
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
            // Genesis anchor — activate this demo user (only when unset).
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

    /**
     * The start (Y-m-d) of the period containing `$now` for a user with the
     * given `period_start_day` — replicates `PeriodQuery::containing()` exactly
     * (that service resolves the period from the *authenticated* user and so
     * cannot be called per-user from a seeder). Keeping the two in lock-step is
     * what makes the seeded `period_start` match the period the live grid folds.
     */
    private function currentPeriodStart(CarbonImmutable $now, int $periodStartDay): string
    {
        $startDay = max(1, min(28, $periodStartDay));
        $candidate = $now->setDay($startDay)->startOfDay();
        $start = $now->day >= $startDay ? $candidate : $candidate->subMonthNoOverflow();

        return $start->toDateString();
    }
}
