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

// EmitPaymentRemindersJob admits every series whose next_expected_at falls in
// [today, today + leadDays], and the reader may set that lead as high as 30
// days. A weekday name cannot tell four Tuesdays apart, and the row is written
// once and read later, so the day it names can be in the past by then.

function trfUser(): User
{
    return User::query()->create([
        'username' => 'trf-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function trfSeries(User $user, string $clusterKey, string $nextExpectedAt, bool $confidenceLow = false): void
{
    RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'TRF '.$clusterKey,
        'display_name_override' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1299,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => CarbonImmutable::parse($nextExpectedAt),
        'next_expected_confidence_low' => $confidenceLow,
        'cluster_key' => $clusterKey,
        'cluster_counterparty_key' => 'TRF '.$clusterKey,
    ]);
}

function trfRunJob(User $user, int $leadDays): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $leadDays): void {
        (new EmitPaymentRemindersJob($user->id, $leadDays))->handle(
            app(RecurringSeriesQuery::class),
            app(RecurringOccurrenceQuery::class),
            app(Dispatcher::class),
            app(Clock::class),
        );
    });
}

/**
 * @return list<string>
 */
function trfTitles(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::PaymentReminder->value)
        ->orderBy('created_at')
        ->pluck('title')
        ->map(static fn (mixed $title): string => (string) $title)
        ->all();
}

/**
 * @return list<string>
 */
function trfBodies(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::PaymentReminder->value)
        ->pluck('body')
        ->map(static fn (mixed $body): string => (string) $body)
        ->all();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-20 09:15:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('tells two Tuesdays four weeks apart apart in the title', function (): void {
    $user = trfUser();

    trfSeries($user, 'trf-next-tuesday', '2026-07-21');
    trfSeries($user, 'trf-tuesday-in-four-weeks', '2026-08-18');

    trfRunJob($user, 30);

    $titles = trfTitles($user);

    expect($titles)->toHaveCount(2)
        ->and($titles[0])->not->toBe($titles[1]);
});

it('gives a hedged reminder a date its body can be read against', function (): void {
    $user = trfUser();

    trfSeries($user, 'trf-hedged', '2026-08-18', confidenceLow: true);

    trfRunJob($user, 30);

    expect(trfBodies($user)[0])->toContain('18 Aug');
});
