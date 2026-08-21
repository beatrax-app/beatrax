<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureImportCompleted;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;

function mobileImportGateUser(): User
{
    return User::query()->create([
        'username' => 'mobile-import-gate-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('mobile-import-gate-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The epoch row marks the device as an admitted peer; the progress row's
// phase is what separates "still pulling" from "converged".
function mobileImportGateState(int $userId, string $phase): void
{
    $now = now()->toDateTimeString();

    DB::table('sync_encryption_state')->insert([
        'user_id' => $userId,
        'current_epoch' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('mobile_sync_progress')->insert([
        'user_id' => $userId,
        'peer_device_id' => 'peer-device',
        'phase' => $phase,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function mobileImportGateRoute(string $uri = '/__test/import-gated'): void
{
    Route::middleware(['web', 'auth', MobileEnsureImportCompleted::class])
        ->get($uri, static fn () => 'REACHED-DASHBOARD')
        ->name('dashboard-ish');
}

// A device that ran the import bootstrap creates its account before it ever
// pairs, so isFreshInstall() is false from then on and nothing routed it back
// into the ceremony it abandoned. The gate reads the durable intent marker
// instead of the ?mode=import query param, which does not survive a relaunch.

it('lets a device that never imported straight through', function (): void {
    $user = mobileImportGateUser();
    mobileImportGateRoute();

    $this->actingAs($user)
        ->get('/__test/import-gated')
        ->assertOk()
        ->assertSee('REACHED-DASHBOARD');
});

it('sends an import device with no epoch back into pairing', function (): void {
    $user = mobileImportGateUser();
    app(MobileImportIntentGate::class)->markImporting($user->id);
    mobileImportGateRoute();

    $this->actingAs($user)
        ->get('/__test/import-gated')
        ->assertRedirect(route('mobile.pair', ['mode' => 'import']));
});

it('sends a paired-but-still-pulling import device to the blocking setup gate', function (): void {
    $user = mobileImportGateUser();
    app(MobileImportIntentGate::class)->markImporting($user->id);

    // The desktop delivered the epoch but the op-log pull has not finished, which
    // is the half-populated state the setup screen exists to hide.
    mobileImportGateState($user->id, 'pulling');

    mobileImportGateRoute();

    $this->actingAs($user)
        ->get('/__test/import-gated')
        ->assertRedirect(route('mobile.setup'));
});

it('retires the marker once the import has converged, and stops gating', function (): void {
    $user = mobileImportGateUser();
    $gate = app(MobileImportIntentGate::class);
    $gate->markImporting($user->id);

    mobileImportGateState($user->id, 'complete');

    mobileImportGateRoute();

    $this->actingAs($user)
        ->get('/__test/import-gated')
        ->assertOk()
        ->assertSee('REACHED-DASHBOARD');

    expect($gate->isImporting($user->id))->toBeFalse();
});

it('never bounces the pairing and setup surfaces it redirects to', function (): void {
    $user = mobileImportGateUser();
    app(MobileImportIntentGate::class)->markImporting($user->id);

    // Without this exemption the gate would redirect mobile.pair to
    // mobile.pair — an infinite loop rather than a recovered ceremony.
    foreach (['mobile.pair' => '/__test/pair', 'mobile.setup' => '/__test/setup'] as $name => $uri) {
        Route::middleware(['web', 'auth', MobileEnsureImportCompleted::class])
            ->get($uri, static fn () => 'CEREMONY')
            ->name($name.'-probe');
    }

    // The probe names carry the exempt prefixes, so the gate must pass them.
    $this->actingAs($user)->get('/__test/pair')->assertOk()->assertSee('CEREMONY');
    $this->actingAs($user)->get('/__test/setup')->assertOk()->assertSee('CEREMONY');
});
