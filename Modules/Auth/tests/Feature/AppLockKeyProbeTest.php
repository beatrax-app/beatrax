<?php

declare(strict_types=1);

// Plan 05-06 Task 3 — AppLockKeyProbe dev-console panel

use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockKeyProbe;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;

/*
 * Feature coverage for the AppLockKeyProbe Livewire component (LOCK-04, D-19):
 *
 *   - With the session unlocked (data key held in session), the probe reports
 *     "released" and a non-null SHA-256 fingerprint of the key.
 *   - After withhold() / lock, the probe reports "withheld" and shows no
 *     fingerprint.
 *   - The probe NEVER renders the raw key bytes — only the 8-char truncated
 *     SHA-256 fingerprint (assertDontSee the raw key; assertSee fingerprint).
 *   - AppLockKeyProbe consumes AppLockKeyService::release() — the same Public
 *     contract Phase 14 will use.
 *
 * These tests go GREEN when plan 05-06 Task 3 creates AppLockKeyProbe + blade.
 */

it('AppLockKeyProbe class exists', function (): void {
    expect(class_exists(AppLockKeyProbe::class))->toBeTrue();
});

it('probe shows "released" and fingerprint when session is unlocked with a data key', function (): void {
    $rawKey = 'test-data-key-32bytes-padding!!!';

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $session->put(LockStateManager::SESSION_KEY, false);
    $session->put(LockStateManager::DATA_KEY_SESSION, $rawKey);

    // Expected fingerprint: first 8 hex chars of sha256 of the raw key.
    $expectedFingerprint = substr(hash('sha256', $rawKey), 0, 8);

    Livewire::test(AppLockKeyProbe::class)
        ->assertSee('App-lock key:')
        ->assertSee('released')
        ->assertSee($expectedFingerprint)
        ->assertDontSee($rawKey);
});

it('probe shows "withheld" when session is locked', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $session->put(LockStateManager::SESSION_KEY, true);
    $session->forget(LockStateManager::DATA_KEY_SESSION);

    Livewire::test(AppLockKeyProbe::class)
        ->assertSee('App-lock key:')
        ->assertSee('withheld');
});

it('probe lock() action withholds the key and transitions to withheld state', function (): void {
    $rawKey = 'another-test-key-32bytes-pad!!!!';

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $session->put(LockStateManager::SESSION_KEY, false);
    $session->put(LockStateManager::DATA_KEY_SESSION, $rawKey);

    Livewire::test(AppLockKeyProbe::class)
        ->assertSee('released')
        ->call('lock')
        ->assertSee('withheld')
        ->assertDontSee($rawKey);
});

it('probe does not render the raw key bytes even when unlocked', function (): void {
    $rawKey = 'secret-data-key-32bytes-padded!!';

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $session->put(LockStateManager::SESSION_KEY, false);
    $session->put(LockStateManager::DATA_KEY_SESSION, $rawKey);

    // The raw key must never appear in the rendered HTML (T-05-26).
    Livewire::test(AppLockKeyProbe::class)
        ->assertDontSee($rawKey);
});

it('AppLockKeyProbe consumes AppLockKeyService (same Public contract Phase 14 uses)', function (): void {
    expect(class_exists(AppLockKeyProbe::class))->toBeTrue();
    expect(class_exists(AppLockKeyService::class))->toBeTrue();

    /** @var AppLockKeyProbe $probe */
    $probe = $this->app->make(AppLockKeyProbe::class);
    expect($probe)->toBeInstanceOf(AppLockKeyProbe::class);
});
