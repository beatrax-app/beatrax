<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// DriftEvaluator emits the SERIES direction — 'expense' or 'income', the
// drift_alerts.direction vocabulary — never 'up' or 'down'. Which way the
// amount moved is the sign of the delta, read against that direction:
// expenses are stored negative, so a bigger bill is a MORE negative delta.

function dndUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function dndOpen(int $userId, int $alertId, string $direction, int $deltaMinor): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($userId, $alertId, $direction, $deltaMinor): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);
        $events->dispatch(new DriftAlertOpened(
            userId: $userId,
            driftAlertId: $alertId,
            recurringSeriesId: 17,
            direction: $direction,
            deltaMinor: $deltaMinor,
            annualizedImpactMinor: $deltaMinor * 12,
            currency: 'EUR',
        ));
    });
}

function dndBody(int $userId): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (string) $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', NotificationTrigger::DriftChanged)
        ->value('body');
}

it('says a dearer subscription moved up', function (): void {
    $user = dndUser('dnd-expense-up');

    dndOpen($user->id, 4301, 'expense', -150);

    expect(dndBody($user->id))->toContain('moved up');
});

it('says a cheaper subscription moved down', function (): void {
    $user = dndUser('dnd-expense-down');

    dndOpen($user->id, 4302, 'expense', 150);

    expect(dndBody($user->id))->toContain('moved down');
});

it('says a raised standing income moved up', function (): void {
    $user = dndUser('dnd-income-up');

    dndOpen($user->id, 4303, 'income', 150);

    expect(dndBody($user->id))->toContain('moved up');
});

it('says a cut standing income moved down', function (): void {
    $user = dndUser('dnd-income-down');

    dndOpen($user->id, 4304, 'income', -150);

    expect(dndBody($user->id))->toContain('moved down');
});
