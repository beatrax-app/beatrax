<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

// A 302 to the HTML lock page is the wrong answer to a client parsing the body
// as JSON: the Android bridge follows it in-process, so the lock page reached
// JSON.parse and wedged the app until a force-stop.

$armLock = function (string $username): User {
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('lock-livewire-pass'),
        'period_start_day' => 1,
    ]);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $user;
};

// Livewire renames a custom update route to end with `livewire.update`, hence
// the wildcard. A probe named the same way needs no component snapshot.
$probeRoute = function (): void {
    Route::middleware(['web', 'auth'])
        ->get('/__lock-livewire-probe', static fn (): string => 'page body')
        ->name('probe.livewire.update');
};

it('answers a locked Livewire request with a JSON body carrying the lock url', function () use ($armLock, $probeRoute): void {
    $this->actingAs($armLock('lock-livewire-user'));
    $probeRoute();

    $response = $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/__lock-livewire-probe');

    $response->assertOk();

    $payload = $response->json();

    expect($payload)->toHaveKey('beatraxLock')
        ->and($payload['beatraxLock']['redirect'])->toBeString()
        ->and($payload['beatraxLock']['redirect'])->not->toBe('');

    // `components: []` leaves a client without the interceptor with no message
    // payload to find, so it morphs nothing rather than throwing.
    expect($payload)->toHaveKey('components')
        ->and($payload['components'])->toBe([]);

    // Refused, not answered: the persistent-middleware pipeline stops only for
    // a RedirectResponse, so anything returned runs the guarded work anyway.
    expect($response->getContent())->not->toContain('page body');
});

it('still redirects an ordinary locked page request', function () use ($armLock): void {
    $this->actingAs($armLock('lock-page-user'));

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/help/data-locations')
        ->assertRedirect();
});

it('does not mistake a leaked X-Livewire header for a Livewire request', function () use ($armLock): void {
    $this->actingAs($armLock('lock-leak-user'));

    // The device failure: a page load carrying a stale X-Livewire header from
    // an earlier POST in the same worker, answered with JSON, rendered the
    // raw payload as the page.
    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->withHeaders(['X-Livewire' => '1'])
        ->get('/help/data-locations')
        ->assertRedirect();
});

it('leaves an unlocked Livewire request alone', function () use ($probeRoute): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'unlocked-livewire-user',
        'password' => bcrypt('unlocked-livewire-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $probeRoute();

    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get('/__lock-livewire-probe');

    $response->assertOk();

    expect($response->getContent())->not->toContain('beatraxLock')
        ->and($response->getContent())->toContain('page body');
});

it('keeps the client and the server naming the same payload key', function (): void {
    // The body is the one part nothing in this stack rewrites. Renaming this
    // key on either side silently restores the wedge.
    expect(file_get_contents(base_path('resources/js/lock.js')))
        ->toContain('beatraxLock');
});
