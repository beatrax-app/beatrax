<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * MOBILE-02 — Wave-0 RED contract test. Turns GREEN in Plan 10 (15-10),
 * which flesh this file out into a full assertion of `SyncScreen`'s
 * rendered states, sourced from the EXISTING Modules\Sync\Public\
 * Services\SyncStatusService (peerStatuses/overallStatus/lastSyncedHuman,
 * consumed as-is) plus the own-module mobile_sync_progress cursor for the
 * active+N-of-M state.
 *
 * Behavior pinned: the status source resolves four states correctly —
 * idle (with a correct relative "Nm ago"/"Nh ago" timestamp), active with
 * N-of-M progress (sourced from mobile_sync_progress.records_applied /
 * records_expected), offline, and a per-device list.
 *
 * RED reason: `Modules\Mobile\Internal\Http\Livewire\SyncScreen` (Plan
 * 10's status surface, per 15-10-PLAN.md) does not exist yet — this is a
 * clean, collectible FAILING assertion (not a class-not-found error), so
 * the suite still collects and reports 4 failing, not errored.
 */
it('resolves idle / active-with-progress / offline / per-device-list sync status states', function (): void {
    $user = User::query()->create([
        'username' => 'mobile-status-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $syncScreenClass = 'Modules\Mobile\Internal\Http\Livewire\SyncScreen';

    expect(class_exists($syncScreenClass))->toBeTrue(
        'SyncScreen does not exist yet — the /sync status surface lands in Plan 10 (15-10). Once it ships, '
        .'flesh this test out into the four-state assertion described in the Task 3 behavior docblock above '
        .'(user id '.$user->id.' seeds the fixture).',
    );
});
