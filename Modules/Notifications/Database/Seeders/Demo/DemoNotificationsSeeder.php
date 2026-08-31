<?php

declare(strict_types=1);

namespace Modules\Notifications\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\TopCategories;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Modules\Recurring\Public\Events\PaymentReminderDue;
use Modules\Recurring\Public\Events\PaymentSettled;
use stdClass;

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
        private readonly PeriodQuery $periods,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users  username => User
     * @param  int  $extraFiller  extra filler rows; the default of 0 leaves
     *                            the realistic ~13-row demo inbox
     */
    public function run(array $users, int $extraFiller = 0): int
    {
        $primary = $users['demo-1'] ?? null;

        if ($primary !== null) {
            // Captured before any setTestNow(): every business date below
            // derives from this one snapshot, so two runs on the same
            // calendar day produce the same rows.
            $realToday = $this->clock->now()->startOfDay();
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

    private function seedCoreEntries(User $user, CarbonImmutable $realToday): void
    {
        $this->seedPaymentReminderEntries($user, $realToday);
        $this->seedBudgetSavingsAndDigestEntries($user, $realToday);
        $this->seedDriftForecastAndImportEntries($user, $realToday);
    }

    private function seedPaymentReminderEntries(User $user, CarbonImmutable $realToday): void
    {
        $kpnSeriesId = $this->seriesId($user, 'demo:kpn:monthly:4500');
        if ($kpnSeriesId !== null) {
            $due = $realToday->addDays(3);
            $this->frozen($realToday->subDays(1)->setTime(9, 0), function () use ($user, $kpnSeriesId, $due): void {
                $this->events->dispatch(new PaymentReminderDue(
                    userId: $user->id,
                    seriesId: $kpnSeriesId,
                    dueDate: $due,
                    confidenceLow: false,
                    expectedAmount: Money::ofMinor(4500, Currency::Eur->value),
                    displayName: 'KPN',
                ));
            });
            $this->markRead($user, NotificationTrigger::PaymentReminder, (string) $kpnSeriesId, $due->toDateString());
        }

        $sportCitySeriesId = $this->seriesId($user, 'demo:sport-city:monthly:2500');
        if ($sportCitySeriesId !== null) {
            $due = $realToday->addDays(2);
            $this->frozen($realToday->subDays(2)->setTime(9, 15), function () use ($user, $sportCitySeriesId, $due): void {
                $this->events->dispatch(new PaymentReminderDue(
                    userId: $user->id,
                    seriesId: $sportCitySeriesId,
                    dueDate: $due,
                    confidenceLow: true,
                    expectedAmount: Money::ofMinor(2500, Currency::Eur->value),
                    displayName: 'Sport City',
                ));
            });
        }

        // The PaymentSettled dispatched below flips this row to 'resolved'
        // through the real ResolveSettledReminder listener.
        $spotifySeriesId = $this->seriesId($user, 'demo:spotify:monthly:1099');
        if ($spotifySeriesId !== null) {
            $due = $realToday->subDays(7);
            $this->frozen($realToday->subDays(6)->setTime(9, 0), function () use ($user, $spotifySeriesId, $due): void {
                $this->events->dispatch(new PaymentReminderDue(
                    userId: $user->id,
                    seriesId: $spotifySeriesId,
                    dueDate: $due,
                    confidenceLow: false,
                    expectedAmount: Money::ofMinor(1099, Currency::Eur->value),
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
            $this->markRead($user, NotificationTrigger::PaymentReminder, (string) $spotifySeriesId, $due->toDateString());
        }

        // Never seeded, so DeepLinkResolver::resolveSeries() finds nothing
        // and the row renders the disabled "no longer exists" state.
        $deadSeriesId = 999_999_999;
        $deadDue = $realToday->subDays(3);
        $this->frozen($realToday->subDays(3)->setTime(10, 0), function () use ($user, $deadSeriesId, $deadDue): void {
            $this->events->dispatch(new PaymentReminderDue(
                userId: $user->id,
                seriesId: $deadSeriesId,
                dueDate: $deadDue,
                confidenceLow: false,
                expectedAmount: Money::ofMinor(999, Currency::Eur->value),
                displayName: 'Discontinued subscription',
            ));
        });
    }

    private function seedBudgetSavingsAndDigestEntries(User $user, CarbonImmutable $realToday): void
    {
        $period = $this->periods->containingForUser($user, $realToday)->start->toDateString();

        $groceries = $this->category('groceries');
        if ($groceries !== null) {
            $this->frozen($realToday->subDays(2)->setTime(18, 0), function () use ($user, $groceries, $period): void {
                $this->events->dispatch(new BudgetThresholdCrossed(
                    userId: $user->id,
                    categoryId: $groceries['id'],
                    categoryName: $groceries['name'],
                    categorySlug: $groceries['slug'],
                    categoryNameIsDefault: $groceries['isDefault'],
                    period: $period,
                    thresholdPercent: 90,
                    spentMinor: 28700,
                    budgetMinor: 30000,
                    currency: Currency::Eur->value,
                ));
            });
            $this->markRead($user, NotificationTrigger::BudgetNudge, (string) $groceries['id'], $period);
        }

        $eatingOut = $this->category('eating-out');
        if ($eatingOut !== null) {
            $this->frozen($realToday->subDays(4)->setTime(18, 30), function () use ($user, $eatingOut, $period): void {
                $this->events->dispatch(new BudgetThresholdCrossed(
                    userId: $user->id,
                    categoryId: $eatingOut['id'],
                    categoryName: $eatingOut['name'],
                    categorySlug: $eatingOut['slug'],
                    categoryNameIsDefault: $eatingOut['isDefault'],
                    period: $period,
                    thresholdPercent: 90,
                    spentMinor: 11500,
                    budgetMinor: 12000,
                    currency: Currency::Eur->value,
                ));
            });
            $this->markRead($user, NotificationTrigger::BudgetNudge, (string) $eatingOut['id'], $period);
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
                    currency: Currency::Eur->value,
                    messageKey: 'drift-alerts::savings.insight.cheaper_message',
                    actionUrl: $this->urls->route('recurring.series.show', ['seriesId' => $netflixSeriesId]),
                ));
            });
            $this->dismiss($user, NotificationTrigger::SavingsPrompt, (string) $netflixSeriesId, $insightKey);
        }

        $weeklyOccurrence = $realToday->isoWeekYear.'-W'.str_pad((string) $realToday->isoWeek, 2, '0', STR_PAD_LEFT);
        $this->frozen($realToday->subDays(1)->setTime(8, 0), function () use ($user, $weeklyOccurrence, $realToday): void {
            $this->events->dispatch(new PositionDigestDue(
                userId: $user->id,
                cadence: DigestCadence::Weekly,
                occurrence: $weeklyOccurrence,
                position: $this->buildPosition($realToday, 320000, 265000, 55000),
            ));
        });
        $this->markRead($user, NotificationTrigger::PositionDigest, 'position', $weeklyOccurrence);
    }

    private function seedDriftForecastAndImportEntries(User $user, CarbonImmutable $realToday): void
    {
        $spotifySeriesId = $this->seriesId($user, 'demo:spotify:monthly:1099');
        $sportCitySeriesId = $this->seriesId($user, 'demo:sport-city:monthly:2500');

        if ($spotifySeriesId !== null) {
            $alert = $this->driftAlertFor($user, $spotifySeriesId);
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
                $this->markRead($user, NotificationTrigger::DriftChanged, (string) $spotifySeriesId, (string) $alert['id']);
            }
        }

        if ($sportCitySeriesId !== null) {
            $alert = $this->driftAlertFor($user, $sportCitySeriesId);
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
                $this->markRead($user, NotificationTrigger::DriftChanged, (string) $sportCitySeriesId, (string) $alert['id']);
            }
        }

        $asnAccountId = $this->accountId($user, 'asn-demo-1');
        if ($asnAccountId !== null) {
            $startsAt = $realToday->addDays(18);
            $endsAt = $realToday->addDays(22);
            $this->frozen($realToday->subDays(1)->setTime(6, 0), function () use ($user, $asnAccountId, $startsAt, $endsAt): void {
                $this->events->dispatch(new ForecastShortfallDetected(
                    userId: $user->id,
                    accountId: $asnAccountId,
                    scenarioId: null,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    lowestBalanceMinor: -8500,
                    currency: Currency::Eur->value,
                    bufferUsedMinor: 50000,
                ));
            });
        }

        $this->frozen($realToday->subDays(3)->setTime(20, 0), function () use ($user): void {
            $this->events->dispatch(new TransactionBatchImported(
                userId: $user->id,
                insertedCount: 24,
                sourceFormats: ['camt053'],
            ));
        });
        $this->markRead(
            $user,
            NotificationTrigger::ImportFinished,
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
            NotificationTrigger::ReceiptsFound,
            'import',
            $this->importOccurrence($realToday->subDays(7)->setTime(21, 0), 3),
        );
    }

    // One row per distinct past day, far outside the core set's range so no
    // occurrence key collides; each is marked read so a large filler count
    // never distorts the nav badge.
    private function seedFiller(User $user, CarbonImmutable $realToday, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $day = $realToday->subDays(100 + $i);
            $occurrence = $day->toDateString();

            $this->frozen($day->setTime(8, 0), function () use ($user, $occurrence, $realToday): void {
                $this->events->dispatch(new PositionDigestDue(
                    userId: $user->id,
                    cadence: DigestCadence::Daily,
                    occurrence: $occurrence,
                    position: $this->buildPosition($realToday, 0, 0, 0),
                ));
            });
            $this->markRead($user, NotificationTrigger::PositionDigest, 'position', $occurrence);
        }
    }

    // Deliberately does not restore between calls: run()'s finally restores
    // the prior test-now, and each frozen() re-pins its own instant.
    private function frozen(CarbonImmutable $at, callable $callback): void
    {
        CarbonImmutable::setTestNow($at);
        $callback();
    }

    private function markRead(User $user, NotificationTrigger $triggerType, string $subjectKey, string $occurrence): void
    {
        $id = $this->keys->derive($user->id, $triggerType, $subjectKey, $occurrence);
        ($this->markReadAction)($id, $user);
    }

    private function dismiss(User $user, NotificationTrigger $triggerType, string $subjectKey, string $occurrence): void
    {
        $id = $this->keys->derive($user->id, $triggerType, $subjectKey, $occurrence);
        ($this->dismissAction)($id, $user);
    }

    private function importOccurrence(CarbonImmutable $at, int $insertedCount): string
    {
        return $at->format('Y-m-d H:i:s').':'.$insertedCount;
    }

    // The digest body only reads summary + budgets + upcoming +
    // shortfallAhead, so the list fields stay empty.
    private function buildPosition(CarbonImmutable $realToday, int $inflowMinor, int $outflowMinor, int $netMinor): PositionSummaryDto
    {
        $period = new Period(
            start: $realToday->startOfMonth(),
            endExclusive: $realToday->startOfMonth()->addMonthNoOverflow(),
            label: $realToday->format('F Y'),
        );

        $summary = new DashboardSummary(
            period: $period,
            inflow: Money::ofMinor($inflowMinor, Currency::Eur->value),
            outflow: Money::ofMinor($outflowMinor, Currency::Eur->value),
            net: Money::ofMinor($netMinor, Currency::Eur->value),
            topCategories: TopCategories::none(Currency::Eur->value),
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
     * @return array{id: int, name: string, slug: string, isDefault: bool}|null
     */
    private function category(string $slug): ?array
    {
        /** @var stdClass|null $row */
        $row = $this->db->connection()->table('categories')
            ->whereNull('user_id')
            ->where('kind', CategoryKind::Expense->value)
            ->where('slug', $slug)
            ->first(['id', ...CategoryDisplayName::bareColumns()]);

        if (! $row instanceof stdClass || ! is_numeric($row->id) || ! is_string($row->name)) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'slug' => $slug,
            'isDefault' => CategoryDisplayName::isDefaultRow($row),
        ];
    }

    private function accountId(User $user, string $slug): ?int
    {
        $id = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->where('slug', $slug)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    // Any state, not only open: the entry records that the alert opened, which
    // happened before the reader could acknowledge or dismiss it. Scoped to
    // open, the demo's dismissed series seeded no notification at all.
    /**
     * @return array{id: int, direction: string, deltaMinor: int, annualizedImpactMinor: int, currency: string}|null
     */
    private function driftAlertFor(User $user, int $seriesId): ?array
    {
        /** @var stdClass|null $row */
        $row = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->orderByDesc('id')
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
}
