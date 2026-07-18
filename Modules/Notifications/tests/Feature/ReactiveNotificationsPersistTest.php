<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\SuppressionEvaluator;

/*
 * Req 3's first acceptance criterion, verbatim: "Triggering each of the 4
 * reactive events produces a corresponding inbox row." All 8 notification
 * types (4 reactive + 4 proactive) now write to the same `notifications`
 * store; this test proves the reactive half — the four events that already
 * fired through `DispatchOsNotification` alone now ALSO persist.
 *
 * The four locked titles are asserted as LITERAL STRINGS so a future reword
 * fails this test (D-29 — the copy is locked, this phase persists and
 * governs it, it does not reword it).
 *
 * Event dispatch is wrapped in SuppressionEvaluator::suppressDelivery()
 * (D-43) so no test ever attempts a real OS notification.
 */

function rnpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function rnpDispatch(object $event): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($event): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);
        $events->dispatch($event);
    });
}

/**
 * @return array<int, string>
 */
function rnpTitlesFor(int $userId, string $triggerType): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', $triggerType)
        ->pluck('title')
        ->map(static fn (mixed $title): string => (string) $title)
        ->all();
}

function rnpCount(int $userId, string $triggerType): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', $triggerType)
        ->count();
}

it('produces one inbox row with the locked "Import finished" title for a csv TransactionBatchImported (Req 3)', function (): void {
    $user = rnpUser('rnp-import-finished');

    rnpDispatch(new TransactionBatchImported(
        userId: $user->id,
        insertedCount: 3,
        sourceFormats: ['csv'],
    ));

    expect(rnpCount($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(1);
    expect(rnpTitlesFor($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED))->toBe(['Import finished']);
});

it('produces one inbox row with the locked "New receipts found" title for an eml TransactionBatchImported (Req 3)', function (): void {
    $user = rnpUser('rnp-receipts-found');

    rnpDispatch(new TransactionBatchImported(
        userId: $user->id,
        insertedCount: 1,
        sourceFormats: ['eml'],
    ));

    expect(rnpCount($user->id, DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND))->toBe(1);
    expect(rnpTitlesFor($user->id, DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND))->toBe(['New receipts found']);
});

it('produces one inbox row with the locked "A recurring charge changed" title for DriftAlertOpened (Req 3)', function (): void {
    $user = rnpUser('rnp-drift-changed');

    rnpDispatch(new DriftAlertOpened(
        userId: $user->id,
        driftAlertId: 4201,
        recurringSeriesId: 17,
        direction: 'up',
        deltaMinor: 250,
        annualizedImpactMinor: 3000,
        currency: 'EUR',
    ));

    expect(rnpCount($user->id, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED))->toBe(1);
    expect(rnpTitlesFor($user->id, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED))->toBe(['A recurring charge changed']);
});

it('produces one inbox row with the locked "Cash-flow shortfall ahead" title for ForecastShortfallDetected (Req 3)', function (): void {
    $user = rnpUser('rnp-forecast-shortfall');

    rnpDispatch(new ForecastShortfallDetected(
        userId: $user->id,
        accountId: 1,
        scenarioId: null,
        startsAt: Carbon::parse('2026-06-01'),
        endsAt: Carbon::parse('2026-06-15'),
        lowestBalanceMinor: -1500,
        currency: 'EUR',
        bufferUsedMinor: 0,
    ));

    expect(rnpCount($user->id, DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL))->toBe(1);
    expect(rnpTitlesFor($user->id, DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL))->toBe(['Cash-flow shortfall ahead']);
});

it('re-dispatching the SAME drift alert still yields exactly one row (D-05 deterministic-id convergence)', function (): void {
    $user = rnpUser('rnp-drift-idempotent');

    $alert = new DriftAlertOpened(
        userId: $user->id,
        driftAlertId: 9911,
        recurringSeriesId: 55,
        direction: 'down',
        deltaMinor: -400,
        annualizedImpactMinor: -4800,
        currency: 'EUR',
    );

    rnpDispatch($alert);
    rnpDispatch($alert);

    expect(rnpCount($user->id, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED))->toBe(1);
});

it('never leaks one user\'s reactive notifications into another user\'s inbox (cross-user)', function (): void {
    $userA = rnpUser('rnp-cross-a');
    $userB = rnpUser('rnp-cross-b');

    rnpDispatch(new DriftAlertOpened(
        userId: $userA->id,
        driftAlertId: 7001,
        recurringSeriesId: 99,
        direction: 'up',
        deltaMinor: 100,
        annualizedImpactMinor: 1200,
        currency: 'EUR',
    ));

    expect(rnpCount($userA->id, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED))->toBe(1);
    expect(rnpCount($userB->id, DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED))->toBe(0);
});
