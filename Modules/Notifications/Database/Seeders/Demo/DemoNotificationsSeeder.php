<?php

declare(strict_types=1);

namespace Modules\Notifications\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon as SupportCarbon;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Enums\NotificationState;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Modules\Recurring\Public\Events\PaymentSettled;
use stdClass;

/**
 * @link ../../../../../.docs/features/notifications/architecture.md
 */
final class DemoNotificationsSeeder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly SuppressionEvaluator $suppression,
        private readonly DeterministicKeyDeriver $keys,
        private readonly MarkNotificationRead $markReadAction,
        private readonly DismissNotification $dismissAction,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * @param  array<string, User>  $users  username => User
     * @param  int  $extraFiller  additional realistic filler rows padding the
     *                            inbox toward Req 2's 50+ UAT case. The
     *                            default demo volume (extraFiller = 0)
     *                            stays realistic (~13 rows).
     */
    public function run(array $users, int $extraFiller = 0): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;

        if ($primary !== null) {
            // Captured once, before any setTestNow() call - every
            // business-date computation below derives from this single
            // real-time snapshot so it stays stable across two runs on
            // the same calendar day.
            $realToday = CarbonImmutable::today();
            $previousTestNow = CarbonImmutable::hasTestNow() ? CarbonImmutable::getTestNow() : null;

            try {
                $this->suppression->suppressDelivery(function () use ($primary, $realToday, $extraFiller): void {
                    $this->seedCoreEntries($primary, $realToday);

                    if ($extraFiller > 0) {
                        $this->seedFiller($primary, $realToday, $extraFiller);
                    }
                });
            } finally {
                CarbonImmutable::setTestNow($previousTestNow);
            }
        }

        return $this->db->connection()->table('notifications')
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    // The 13-row default set - one entry per demo scenario, covering all
    // 8 trigger types and five interesting states (unread, read,
    // dismissed, resolved, dead-link). Delegates to three cohesive
    // grouped seeders to stay well under the per-method length ceiling.
    private function seedCoreEntries(User $user, CarbonImmutable $realToday): void
    {
        $this->seedPaymentReminderEntries($user, $realToday);
        $this->seedBudgetSavingsAndDigestEntries($user, $realToday);
        $this->seedDriftForecastAndImportEntries($user, $realToday);
    }

    // The four payment-reminder scenarios: a confident upcoming reminder
    // (marked read), the hedged variant (unread), a reminder that later
    // settles into 'resolved', and a dead-link reminder for a series id
    // that was never seeded.
    private function seedPaymentReminderEntries(User $user, CarbonImmutable $realToday): void
    {
        // Payment reminders: r1 is a confident reminder, upcoming, on an
        // existing approved series - marked read below.
        $kpnSeriesId = $this->seriesId($user, 'demo:kpn:monthly:4500');
        if ($kpnSeriesId !== null) {
            $due = $realToday->addDays(3);
            $this->frozen($realToday->subDays(1)->setTime(9, 0), function () use ($user, $kpnSeriesId, $due): void {
                $this->events->dispatch(new PaymentReminderDue(
                    userId: $user->id,
                    seriesId: $kpnSeriesId,
                    dueDate: $due,
                    confidenceLow: false,
                    expectedAmount: Money::ofMinor(4500, 'EUR'),
                    displayName: 'KPN',
                ));
            });
            $this->markRead($user, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, (string) $kpnSeriesId, $due->toDateString());
        }

        // r2: the hedged variant, side by side with r1 - left
        // unread.
        $sportCitySeriesId = $this->seriesId($user, 'demo:sport-city:monthly:2500');
        if ($sportCitySeriesId !== null) {
            $due = $realToday->addDays(2);
            $this->frozen($realToday->subDays(2)->setTime(9, 15), function () use ($user, $sportCitySeriesId, $due): void {
                $this->events->dispatch(new PaymentReminderDue(
                    userId: $user->id,
                    seriesId: $sportCitySeriesId,
                    dueDate: $due,
                    confidenceLow: true,
                    expectedAmount: Money::ofMinor(2500, 'EUR'),
                    displayName: 'Sport City',
                ));
            });
        }

        // r3: the resolved/withdrawn outcome - a reminder that later
        // settles via a matching PaymentSettled, flipping the row to
        // 'resolved' through the real ResolveSettledReminder listener.
        $spotifySeriesId = $this->seriesId($user, 'demo:spotify:monthly:1099');
        if ($spotifySeriesId !== null) {
            $due = $realToday->subDays(7);
            $this->frozen($realToday->subDays(6)->setTime(9, 0), function () use ($user, $spotifySeriesId, $due): void {
                $this->events->dispatch(new PaymentReminderDue(
                    userId: $user->id,
                    seriesId: $spotifySeriesId,
                    dueDate: $due,
                    confidenceLow: false,
                    expectedAmount: Money::ofMinor(1099, 'EUR'),
                    displayName: 'Spotify Premium',
                ));
            });
            $this->frozen($realToday->subDays(1)->setTime(14, 0), function () use ($user, $spotifySeriesId, $due): void {
                $this->events->dispatch(new PaymentSettled(
                    userId: $user->id,
                    seriesId: $spotifySeriesId,
                    dueDate: $due,
                ));
            });
            $this->markRead($user, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, (string) $spotifySeriesId, $due->toDateString());
        }

        // r4: the dead-link outcome - a reminder for a series id that was
        // never seeded, so DeepLinkResolver::resolveSeries() finds nothing
        // and renders the disabled "no longer exists" state. Left unread.
        $deadSeriesId = 999_999_999;
        $deadDue = $realToday->subDays(3);
        $this->frozen($realToday->subDays(3)->setTime(10, 0), function () use ($user, $deadSeriesId, $deadDue): void {
            $this->events->dispatch(new PaymentReminderDue(
                userId: $user->id,
                seriesId: $deadSeriesId,
                dueDate: $deadDue,
                confidenceLow: false,
                expectedAmount: Money::ofMinor(999, 'EUR'),
                displayName: 'Discontinued subscription',
            ));
        });
    }

    // The two budget-nudge crossings (both marked read), the dismissed
    // savings prompt, and the weekly position digest (marked read). The
    // envelope period key is resolved once here since only the budget
    // nudges consume it.
    private function seedBudgetSavingsAndDigestEntries(User $user, CarbonImmutable $realToday): void
    {
        $period = $this->currentPeriodStart($realToday, (int) $user->period_start_day);

        $groceries = $this->category('groceries');
        if ($groceries !== null) {
            $this->frozen($realToday->subDays(2)->setTime(18, 0), function () use ($user, $groceries, $period): void {
                $this->events->dispatch(new BudgetThresholdCrossed(
                    userId: $user->id,
                    categoryId: $groceries['id'],
                    categoryName: $groceries['name'],
                    period: $period,
                    thresholdPercent: 90,
                    spentMinor: 28700,
                    budgetMinor: 30000,
                    currency: 'EUR',
                ));
            });
            $this->markRead($user, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE, (string) $groceries['id'], $period);
        }

        $eatingOut = $this->category('eating-out');
        if ($eatingOut !== null) {
            $this->frozen($realToday->subDays(4)->setTime(18, 30), function () use ($user, $eatingOut, $period): void {
                $this->events->dispatch(new BudgetThresholdCrossed(
                    userId: $user->id,
                    categoryId: $eatingOut['id'],
                    categoryName: $eatingOut['name'],
                    period: $period,
                    thresholdPercent: 90,
                    spentMinor: 11500,
                    budgetMinor: 12000,
                    currency: 'EUR',
                ));
            });
            $this->markRead($user, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE, (string) $eatingOut['id'], $period);
        }

        $netflixSeriesId = $this->seriesId($user, 'demo:netflix:monthly:1499');
        if ($netflixSeriesId !== null) {
            $insightKey = 'cheaper:'.$netflixSeriesId;
            $this->frozen($realToday->subDays(5)->setTime(11, 0), function () use ($user, $netflixSeriesId, $insightKey): void {
                $this->events->dispatch(new SavingsPromptDue(
                    userId: $user->id,
                    insightKey: $insightKey,
                    seriesId: $netflixSeriesId,
                    name: 'Netflix',
                    monthlyMinor: 1499,
                    currency: 'EUR',
                    message: 'A cheaper Netflix plan could save you money.',
                    actionUrl: $this->urls->route('recurring.series.show', ['seriesId' => $netflixSeriesId]),
                ));
            });
            $this->dismiss($user, DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT, (string) $netflixSeriesId, $insightKey);
        }

        $weeklyOccurrence = $realToday->isoWeekYear.'-W'.str_pad((string) $realToday->isoWeek, 2, '0', STR_PAD_LEFT);
        $this->frozen($realToday->subDays(1)->setTime(8, 0), function () use ($user, $weeklyOccurrence, $realToday): void {
            $this->events->dispatch(new PositionDigestDue(
                userId: $user->id,
                cadence: 'weekly',
                occurrence: $weeklyOccurrence,
                position: $this->buildPosition($realToday, 320000, 265000, 55000),
            ));
        });
        $this->markRead($user, DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST, 'position', $weeklyOccurrence);
    }

    // The two drift alerts on already-seeded series (both marked read),
    // the always-deliverable forecast shortfall, and the two coalesced
    // imports (import-finished read, receipts-found dismissed). The
    // series ids are re-resolved so each grouped seeder is self-contained.
    private function seedDriftForecastAndImportEntries(User $user, CarbonImmutable $realToday): void
    {
        $spotifySeriesId = $this->seriesId($user, 'demo:spotify:monthly:1099');
        $sportCitySeriesId = $this->seriesId($user, 'demo:sport-city:monthly:2500');

        if ($spotifySeriesId !== null) {
            $alert = $this->openDriftAlert($user, $spotifySeriesId);
            if ($alert !== null) {
                $this->frozen($realToday->subDays(2)->setTime(7, 45), function () use ($user, $spotifySeriesId, $alert): void {
                    $this->events->dispatch(new DriftAlertOpened(
                        userId: $user->id,
                        driftAlertId: $alert['id'],
                        recurringSeriesId: $spotifySeriesId,
                        direction: $alert['direction'],
                        deltaMinor: $alert['deltaMinor'],
                        annualizedImpactMinor: $alert['annualizedImpactMinor'],
                        currency: $alert['currency'],
                    ));
                });
                $this->markRead($user, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED, (string) $spotifySeriesId, (string) $alert['id']);
            }
        }

        if ($sportCitySeriesId !== null) {
            $alert = $this->openDriftAlert($user, $sportCitySeriesId);
            if ($alert !== null) {
                $this->frozen($realToday->subDays(5)->setTime(7, 45), function () use ($user, $sportCitySeriesId, $alert): void {
                    $this->events->dispatch(new DriftAlertOpened(
                        userId: $user->id,
                        driftAlertId: $alert['id'],
                        recurringSeriesId: $sportCitySeriesId,
                        direction: $alert['direction'],
                        deltaMinor: $alert['deltaMinor'],
                        annualizedImpactMinor: $alert['annualizedImpactMinor'],
                        currency: $alert['currency'],
                    ));
                });
                $this->markRead($user, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED, (string) $sportCitySeriesId, (string) $alert['id']);
            }
        }

        $asnAccountId = $this->accountId($user, 'asn-demo-1');
        if ($asnAccountId !== null) {
            // ForecastShortfallDetected carries Illuminate\Support\Carbon
            // (not CarbonImmutable, unlike every other event in this
            // seeder) - Carbon::instance() converts without disturbing
            // the CarbonImmutable-only test-now freeze used elsewhere.
            $startsAt = SupportCarbon::instance($realToday->addDays(18));
            $endsAt = SupportCarbon::instance($realToday->addDays(22));
            $this->frozen($realToday->subDays(1)->setTime(6, 0), function () use ($user, $asnAccountId, $startsAt, $endsAt): void {
                $this->events->dispatch(new ForecastShortfallDetected(
                    userId: $user->id,
                    accountId: $asnAccountId,
                    scenarioId: null,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    lowestBalanceMinor: -8500,
                    currency: 'EUR',
                    bufferUsedMinor: 50000,
                ));
            });
        }

        // r12 ("Import finished") is marked read; r13 ("New receipts
        // found") below is dismissed.
        $this->frozen($realToday->subDays(3)->setTime(20, 0), function () use ($user): void {
            $this->events->dispatch(new TransactionBatchImported(
                userId: $user->id,
                insertedCount: 24,
                sourceFormats: ['camt053'],
            ));
        });
        $this->markRead(
            $user,
            DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
            'import',
            $this->importOccurrence($realToday->subDays(3)->setTime(20, 0), 24),
        );

        $this->frozen($realToday->subDays(7)->setTime(21, 0), function () use ($user): void {
            $this->events->dispatch(new TransactionBatchImported(
                userId: $user->id,
                insertedCount: 3,
                sourceFormats: ['eml'],
            ));
        });
        $this->dismiss(
            $user,
            DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
            'import',
            $this->importOccurrence($realToday->subDays(7)->setTime(21, 0), 3),
        );
    }

    // Pads the inbox with additional, distinct position-digest rows (one
    // per distinct past day well outside the core set's date range so no
    // occurrence key collides). Every filler row is immediately marked
    // read so a large count never distorts the nav badge.
    private function seedFiller(User $user, CarbonImmutable $realToday, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $day = $realToday->subDays(100 + $i);
            $occurrence = $day->toDateString();

            $this->frozen($day->setTime(8, 0), function () use ($user, $occurrence, $realToday): void {
                $this->events->dispatch(new PositionDigestDue(
                    userId: $user->id,
                    cadence: 'daily',
                    occurrence: $occurrence,
                    position: $this->buildPosition($realToday, 0, 0, 0),
                ));
            });
            $this->markRead($user, DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST, 'position', $occurrence);
        }
    }

    // Runs $callback with CarbonImmutable's global test-now pinned to
    // $at. The caller (run()) restores the prior test-now value
    // afterward; this helper deliberately does not restore between
    // calls, since the next frozen() call simply re-pins its own instant.
    private function frozen(CarbonImmutable $at, callable $callback): void
    {
        CarbonImmutable::setTestNow($at);
        $callback();
    }

    private function markRead(User $user, string $triggerType, string $subjectKey, string $occurrence): void
    {
        $id = $this->keys->derive($user->id, $triggerType, $subjectKey, $occurrence);
        ($this->markReadAction)($id, $user);
    }

    private function dismiss(User $user, string $triggerType, string $subjectKey, string $occurrence): void
    {
        $id = $this->keys->derive($user->id, $triggerType, $subjectKey, $occurrence);
        ($this->dismissAction)($id, $user);
    }

    // Mirrors PersistCoalescedImport::handle()'s occurrence-key formula
    // exactly.
    private function importOccurrence(CarbonImmutable $at, int $insertedCount): string
    {
        return $at->format('Y-m-d H:i:s').':'.$insertedCount;
    }

    // A minimal, hand-built DTO - no cross-module composition needed
    // since the seeder controls the demo figures directly. The list
    // fields stay empty; the digest body only reads the summary +
    // budgets + upcoming + shortfallAhead fields.
    private function buildPosition(CarbonImmutable $realToday, int $inflowMinor, int $outflowMinor, int $netMinor): PositionSummaryDto
    {
        $period = new Period(
            start: $realToday->startOfMonth(),
            endExclusive: $realToday->startOfMonth()->addMonthNoOverflow(),
            label: $realToday->format('F Y'),
        );

        $summary = new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor($inflowMinor, 'EUR'),
            outflow: Money::ofMinor($outflowMinor, 'EUR'),
            net: Money::ofMinor($netMinor, 'EUR'),
            topCategories: [],
            recentTransactions: [],
            uncategorizedCount: 0,
            isFirstRun: false,
        );

        return new PositionSummaryDto(
            summary: $summary,
            tilesByCurrency: null,
            emailScanHealth: null,
            upcoming: [],
            budgets: [],
            shortfallAhead: false,
        );
    }

    private function seriesId(User $user, string $clusterKey): ?int
    {
        $id = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('cluster_key', $clusterKey)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function category(string $slug): ?array
    {
        /** @var stdClass|null $row */
        $row = $this->db->connection()->table('categories')
            ->whereNull('user_id')
            ->where('kind', CategoryKind::Expense->value)
            ->where('slug', $slug)
            ->first(['id', 'name']);

        if (! $row instanceof stdClass || ! is_numeric($row->id) || ! is_string($row->name)) {
            return null;
        }

        return ['id' => (int) $row->id, 'name' => $row->name];
    }

    private function accountId(User $user, string $slug): ?int
    {
        $id = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->where('slug', $slug)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array{id: int, direction: string, deltaMinor: int, annualizedImpactMinor: int, currency: string}|null
     */
    private function openDriftAlert(User $user, int $seriesId): ?array
    {
        /** @var stdClass|null $row */
        $row = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->where('state', NotificationState::Open->value)
            ->first(['id', 'direction', 'delta_minor', 'annualized_impact_minor', 'currency']);

        if (! $row instanceof stdClass
            || ! is_numeric($row->id)
            || ! is_string($row->direction)
            || ! is_numeric($row->delta_minor)
            || ! is_numeric($row->annualized_impact_minor)
            || ! is_string($row->currency)
        ) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'direction' => $row->direction,
            'deltaMinor' => (int) $row->delta_minor,
            'annualizedImpactMinor' => (int) $row->annualized_impact_minor,
            'currency' => $row->currency,
        ];
    }

    // Replicates DemoEnvelopeBudgetsSeeder::currentPeriodStart() exactly
    // (that service resolves the period via PeriodQuery, which cannot be
    // called per-user from a seeder) - kept in lock-step so the
    // occurrence key matches what the live envelope grid computes.
    private function currentPeriodStart(CarbonImmutable $now, int $periodStartDay): string
    {
        $startDay = max(1, min(28, $periodStartDay));
        $candidate = $now->setDay($startDay)->startOfDay();
        $start = $now->day >= $startDay ? $candidate : $candidate->subMonthNoOverflow();

        return $start->toDateString();
    }
}
