<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

// The lead time is a constructor argument because Recurring never reads
// Modules\Notifications. Dispatch runs inside suppressDelivery() so no case
// here attempts a real OS notification.

function prtUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function prtSeries(User $user, string $clusterKey, ?CarbonImmutable $nextExpectedAt, bool $confidenceLow = false): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'PRT MERCHANT',
        'display_name_override' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1299,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => $nextExpectedAt,
        'next_expected_confidence_low' => $confidenceLow,
        'cluster_key' => $clusterKey,
        'cluster_counterparty_key' => 'PRT MERCHANT',
    ]);
}

function prtRunJob(User $user, int $leadDays): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $leadDays): void {
        $job = new EmitPaymentRemindersJob($user->id, $leadDays);
        $job->handle(
            app(RecurringSeriesQuery::class),
            app(RecurringOccurrenceQuery::class),
            app(Dispatcher::class),
            app(Clock::class),
        );
    });
}

function prtNotificationCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', NotificationTrigger::PaymentReminder)
        ->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-20 09:15:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('emits exactly one reminder for a series inside the lead-time window', function (): void {
    $user = prtUser('prt-inside-window');
    prtSeries($user, 'prt-inside', CarbonImmutable::parse('2026-07-22'));

    prtRunJob($user, 3);

    expect(prtNotificationCount($user->id))->toBe(1);
});

it('produces still exactly one row when the job runs a second time the same day', function (): void {
    $user = prtUser('prt-idempotent-same-day');
    prtSeries($user, 'prt-idempotent', CarbonImmutable::parse('2026-07-22'));

    prtRunJob($user, 3);
    prtRunJob($user, 3);

    expect(prtNotificationCount($user->id))->toBe(1);
});

it('emits none when there are no upcoming charges', function (): void {
    $user = prtUser('prt-no-upcoming');
    // Beyond the lead window.
    prtSeries($user, 'prt-far-future', CarbonImmutable::parse('2026-09-01'));
    // No next-expected date at all.
    prtSeries($user, 'prt-null-next', null);

    prtRunJob($user, 3);

    expect(prtNotificationCount($user->id))->toBe(0);
});

it('honours the lead-time preference: 0 rows at lead 3, 1 row at lead 7 for the same 5-day-out series', function (): void {
    $user = prtUser('prt-lead-time');
    prtSeries($user, 'prt-lead', CarbonImmutable::parse('2026-07-25'));

    prtRunJob($user, 3);
    expect(prtNotificationCount($user->id))->toBe(0);

    prtRunJob($user, 7);
    expect(prtNotificationCount($user->id))->toBe(1);
});

it('stores a hedged title for a low-confidence series, distinct from the confident variant', function (): void {
    $confidentUser = prtUser('prt-confident');
    prtSeries($confidentUser, 'prt-confident-series', CarbonImmutable::parse('2026-07-22'));
    prtRunJob($confidentUser, 3);

    $hedgedUser = prtUser('prt-hedged');
    prtSeries($hedgedUser, 'prt-hedged-series', CarbonImmutable::parse('2026-07-22'), confidenceLow: true);
    prtRunJob($hedgedUser, 3);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $confidentTitle = $db->connection()->table('notifications')
        ->where('user_id', $confidentUser->id)
        ->where('trigger_type', NotificationTrigger::PaymentReminder)
        ->value('title');

    $hedgedTitle = $db->connection()->table('notifications')
        ->where('user_id', $hedgedUser->id)
        ->where('trigger_type', NotificationTrigger::PaymentReminder)
        ->value('title');

    expect($confidentTitle)->not->toBe($hedgedTitle);
    expect((string) $hedgedTitle)->toContain('around');
});

it('keys the occurrence on the due date, so a fire-date shift does not fracture the reminder', function (): void {
    $user = prtUser('prt-due-date-key');
    prtSeries($user, 'prt-due-date', CarbonImmutable::parse('2026-07-22'));

    prtRunJob($user, 3);
    expect(prtNotificationCount($user->id))->toBe(1);

    // The fire date moves but the series' due date does not, so the same id
    // is re-derived and the write is a no-op.
    CarbonImmutable::setTestNow('2026-07-21 09:15:00');
    prtRunJob($user, 3);

    expect(prtNotificationCount($user->id))->toBe(1);
});
