<?php

declare(strict_types=1);

namespace Modules\Budgets\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Internal\Support\EnvelopeMoveId;
use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Database\Seeders\Demo\DemoPeriodWindow;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Services\BaseCurrency;

/**
 * @link ../../../../../.docs/features/budgets/what-the-demo-envelope-grid-has-to-show.md
 */
final class DemoEnvelopeBudgetsSeeder
{
    // Assigned minor per category, oldest period first, near enough to the
    // seeded spend that each month lands over or under by a plausible margin.
    // Several spending categories are left out on purpose, so the grid shows a
    // positive "Ready to assign" pool rather than a wall of red.
    /**
     * @var array<string, array<int, int>>
     */
    private const BUDGETS = [
        'housing-rent' => [130000, 130000, 130000],
        'housing-utilities' => [15000, 15000, 15000],
        'housing-internet' => [11000, 11000, 11000],
        'groceries' => [40000, 38000, 30000],
        'eating-out' => [15000, 15000, 15000],
        'transport-fuel' => [8000, 8000, 8000],
        'transport-public' => [10000, 10000, 10000],
        'insurance-health' => [14000, 14000, 14000],
        'subscriptions-streaming' => [1500, 1500, 1500],
        'subscriptions-cloud' => [22000, 18000, 12000],
        'personal-care' => [2500, 2500, 2500],
    ];

    // Both modes, because the only place they differ is an envelope that ends
    // a period below zero: one rolls the deficit into the next month and the
    // other absorbs it. With no row here every category took the default and
    // the choice was undemonstrable.
    /**
     * @var array<string, OverspendMode>
     */
    private const OVERSPEND_MODES = [
        'eating-out' => OverspendMode::CarryNegative,
        'insurance-health' => OverspendMode::CarryNegative,
        'groceries' => OverspendMode::ReduceToBudget,
        'transport-public' => OverspendMode::ReduceToBudget,
    ];

    // Off the 90 default deliberately: with every row on the default, the
    // notify column and the nudge job read the same number either way, and
    // nothing on screen proves the stored one is what either reads.
    /**
     * @var array<string, int>
     */
    private const NOTIFY_THRESHOLDS = [
        'groceries' => 75,
        'eating-out' => 60,
    ];

    // In the current period, from an envelope with room to one without: the
    // grid's history list and its undo control are both period-scoped, so a
    // move seeded into any other month renders nowhere at all.
    /**
     * @var array<int, array{from: string, to: string, minor: int, memoKey: string}>
     */
    private const MOVES = [
        ['from' => 'transport-fuel', 'to' => 'eating-out', 'minor' => 5000, 'memoKey' => 'envelope_move_eating_out'],
        ['from' => 'housing-utilities', 'to' => 'personal-care', 'minor' => 2500, 'memoKey' => 'envelope_move_personal_care'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly BaseCurrency $baseCurrency,
        private readonly DemoPeriodWindow $demoWindow,
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
            $periods = $this->demoWindow->forUser($user, $now);

            // Stamped every run, not only when null: `--reset` drops the
            // assignments and writes new ones at today's period, and a stamp
            // left from an earlier seed put genesis months before the oldest
            // row the fold could then find.
            $connection->table('users')
                ->where('id', $user->id)
                ->update(['envelope_activated_at' => $periods[0]->start->toDateTimeString()]);

            // Written directly rather than through EnvelopeWriter, so the rule
            // that stamps this column has to be restated: `demo:seed` is an
            // artisan command with nobody authenticated, and code() answers
            // there with the install default instead of this user's own.
            $currency = $this->baseCurrency->forUser($user);

            $seeded += $this->seedAssignments($user, $periods, $categoryIdBySlug, $currency, $now);
            $this->seedSettings($user, $categoryIdBySlug, $now);
            $this->seedMoves($user, end($periods), $categoryIdBySlug, $currency, $now);
        }

        return $seeded;
    }

    /**
     * @param  array<int, Period>  $periods
     * @param  array<string, int>  $categoryIdBySlug
     */
    private function seedAssignments(User $user, array $periods, array $categoryIdBySlug, string $currency, CarbonImmutable $now): int
    {
        $connection = $this->db->connection();
        $seeded = 0;

        foreach (self::BUDGETS as $slug => $perPeriod) {
            $categoryId = $categoryIdBySlug[$slug] ?? 0;
            if ($categoryId === 0) {
                continue;
            }

            foreach ($periods as $index => $period) {
                $periodStart = $period->start->toDateString();
                $minor = $perPeriod[$index] ?? end($perPeriod);

                $connection->table('envelope_assignments')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'category_id' => $categoryId,
                        'period_start' => $periodStart,
                    ],
                    [
                        'assigned_minor' => $minor,
                        'currency' => $currency,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $seeded++;
            }
        }

        return $seeded;
    }

    /** @param array<string, int> $categoryIdBySlug */
    private function seedSettings(User $user, array $categoryIdBySlug, CarbonImmutable $now): void
    {
        $connection = $this->db->connection();

        $slugs = array_unique([...array_keys(self::OVERSPEND_MODES), ...array_keys(self::NOTIFY_THRESHOLDS)]);

        foreach ($slugs as $slug) {
            $categoryId = $categoryIdBySlug[$slug] ?? 0;
            if ($categoryId === 0) {
                continue;
            }

            $mode = self::OVERSPEND_MODES[$slug] ?? OverspendMode::ReduceToBudget;

            $connection->table('envelope_settings')->updateOrInsert(
                ['user_id' => $user->id, 'category_id' => $categoryId],
                [
                    'overspend_mode' => $mode->value,
                    'threshold_percent' => self::NOTIFY_THRESHOLDS[$slug] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    // The pair, not one row: the grid nets amount_minor per category and
    // undoMove() finds the counterpart by the shared move_group_id, so a
    // single-sided row would read as money appearing from nowhere.
    /** @param array<string, int> $categoryIdBySlug */
    private function seedMoves(User $user, Period $period, array $categoryIdBySlug, string $currency, CarbonImmutable $now): void
    {
        $connection = $this->db->connection();
        $periodStart = $period->start->toDateString();

        foreach (self::MOVES as $move) {
            $fromId = $categoryIdBySlug[$move['from']] ?? 0;
            $toId = $categoryIdBySlug[$move['to']] ?? 0;

            if ($fromId === 0 || $toId === 0) {
                continue;
            }

            $memo = Lang::get('core::demo.'.$move['memoKey']);

            $already = $connection->table('envelope_moves')
                ->where('user_id', $user->id)
                ->where('category_id', $fromId)
                ->where('counterpart_category_id', $toId)
                ->where('period_start', $periodStart)
                ->exists();

            if ($already) {
                continue;
            }

            // Derived from the demo move itself, never minted: every device
            // seeds this same move, and a fresh uuid on each would make one
            // demo move two the moment the two of them synced.
            $groupId = self::demoGroupId(self::asText($user->username), $move['from'], $move['to'], self::asText($move['memoKey']), $periodStart);

            foreach ([
                ['category_id' => $fromId, 'counterpart_category_id' => $toId, 'amount_minor' => -$move['minor'], 'kind' => EnvelopeMoveKind::MoveOut],
                ['category_id' => $toId, 'counterpart_category_id' => $fromId, 'amount_minor' => $move['minor'], 'kind' => EnvelopeMoveKind::MoveIn],
            ] as $leg) {
                $connection->table('envelope_moves')->insert([
                    'id' => EnvelopeMoveId::for($groupId, $leg['kind'], $periodStart),
                    'user_id' => $user->id,
                    'category_id' => $leg['category_id'],
                    'counterpart_category_id' => $leg['counterpart_category_id'],
                    'period_start' => $periodStart,
                    'amount_minor' => $leg['amount_minor'],
                    'currency' => $currency,
                    'kind' => $leg['kind']->value,
                    'memo' => $memo,
                    'move_group_id' => $groupId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    // The slugs and the memo key identify the demo move on every device, and
    // the period start keeps two installs a month apart from claiming one row.
    // The username rather than the id: it is unique, and it is the same string
    // on both devices where an autoincrement need not be.
    private static function demoGroupId(string $username, string $from, string $to, string $memoKey, string $periodStart): string
    {
        return 'demo-'.substr(hash('sha256', implode('|', [$username, $from, $to, $memoKey, $periodStart])), 0, 32);
    }

    private static function asText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
