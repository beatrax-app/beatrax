<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\SuppressionEvaluator;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/*
 * Req 4 — upcoming fixed-payment reminders. Exercises
 * EmitPaymentRemindersJob end-to-end: series inside/outside the lead-time
 * window, the D-15 lead-time preference (passed as a constructor arg —
 * Recurring never reads Modules\Notifications), the D-17 confident/hedged
 * title pair, and the D-06 due-date occurrence key surviving a fire-date
 * shift.
 *
 * Event dispatch is wrapped in SuppressionEvaluator::suppressDelivery()
 * (D-43) so no test ever attempts a real OS notification.
 */

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
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER)
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
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER)
        ->value('title');

    $hedgedTitle = $db->connection()->table('notifications')
        ->where('user_id', $hedgedUser->id)
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER)
        ->value('title');

    expect($confidentTitle)->not->toBe($hedgedTitle);
    expect((string) $hedgedTitle)->toContain('around');
});

it('keys the occurrence on the due date, so a fire-date shift does not fracture the reminder', function (): void {
    $user = prtUser('prt-due-date-key');
    prtSeries($user, 'prt-due-date', CarbonImmutable::parse('2026-07-22'));

    prtRunJob($user, 3);
    expect(prtNotificationCount($user->id))->toBe(1);

    // Advance the fire date by one day; the due date on the series row is
    // unchanged, so the same notification id is re-derived and the write
    // is a no-op (D-06).
    CarbonImmutable::setTestNow('2026-07-21 09:15:00');
    prtRunJob($user, 3);

    expect(prtNotificationCount($user->id))->toBe(1);
});
