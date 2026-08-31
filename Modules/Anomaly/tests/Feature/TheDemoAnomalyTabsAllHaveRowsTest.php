<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The backfill only ever emits `open`, so the History and Dismissed tabs of
// /drift?type=anomaly were both empty on a freshly seeded install.

it('fills every tab the anomaly view offers', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    $query = app(AnomalyAlertQuery::class);

    expect($query->openForUser($user))->not->toBeEmpty()
        ->and($query->historyForUser($user))->not->toBeEmpty()
        ->and($query->dismissedForUser($user))->not->toBeEmpty();
});

// Walked through the real actions, so each actioned alert carries the
// transition a hand-written state column would have left behind.
it('records a transition for every alert the seed actioned', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    $query = app(AnomalyAlertQuery::class);

    $actioned = array_merge($query->historyForUser($user), $query->dismissedForUser($user));

    expect($actioned)->not->toBeEmpty();

    foreach ($actioned as $alert) {
        $transitions = DB::table('anomaly_alert_transitions')
            ->where('user_id', $user->id)
            ->where('anomaly_alert_id', $alert->anomalyAlertId)
            ->count();

        expect($transitions)->toBeGreaterThan(0, "alert {$alert->anomalyAlertId} changed state without a transition");
    }
});
