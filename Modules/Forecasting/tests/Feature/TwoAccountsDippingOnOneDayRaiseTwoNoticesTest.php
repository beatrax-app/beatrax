<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/scenario-isolation.md */
const TAD_DIP_DATE = '2026-09-04';

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'tad',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

function tadDispatch(ForecastShortfallDetected $event): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    // Dispatch inside suppressDelivery so no case attempts a real OS
    // notification, exactly as ReactiveNotificationsPersistTest does.
    $suppression->suppressDelivery(function () use ($event): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);
        $events->dispatch($event);
    });
}

function tadShortfall(int $userId, int $accountId, ?int $scenarioId = null): ForecastShortfallDetected
{
    return new ForecastShortfallDetected(
        userId: $userId,
        accountId: $accountId,
        scenarioId: $scenarioId,
        startsAt: CarbonImmutable::parse(TAD_DIP_DATE),
        endsAt: CarbonImmutable::parse('2026-09-09'),
        lowestBalanceMinor: -1500,
        currency: Currency::Eur->value,
        bufferUsedMinor: 0,
    );
}

// Counted whole rather than by trigger type: a shortfall event is the only
// thing this file dispatches, and the trigger vocabulary is Notifications'
// own Internal surface.
function tadInboxCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->count();
}

// The dedupe key was (user, trigger, 'forecast', startsAt), so the second
// account's dip derived the same row id as the first and vanished into it.
it('tells the reader about both accounts when two of them dip on the same day', function (): void {
    tadDispatch(tadShortfall($this->user->id, 41));
    tadDispatch(tadShortfall($this->user->id, 42));

    expect(tadInboxCount($this->user->id))->toBe(2);
});

it('still converges on one row when the same account dip is announced twice', function (): void {
    tadDispatch(tadShortfall($this->user->id, 41));
    tadDispatch(tadShortfall($this->user->id, 41));

    expect(tadInboxCount($this->user->id))->toBe(1);
});

it('keeps a what-if dip out of the inbox', function (): void {
    tadDispatch(tadShortfall($this->user->id, 41, scenarioId: 7));

    expect(tadInboxCount($this->user->id))->toBe(0);
});
