<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * What a locked session says to a Livewire XHR, and how it decides that is
 * what it is looking at.
 *
 * A 302 to the HTML lock page is the wrong answer to a request whose client
 * reads the body as JSON. On Android it was a fatal one: NativePHP's bridge
 * follows the redirect in-process, so `response.redirected` is false, the lock
 * page's HTML reached `JSON.parse`, and the app was left with the old
 * component and the new page half-mounted — lock screen painted as a narrow
 * inset column, then blank, then no request from any tap until a force-stop.
 *
 * The recognition matters as much as the answer. The obvious signal is the
 * `X-Livewire` request header, and on the Android runtime it is a trap: the
 * PHP process is persistent, `HTTP_X_LIVEWIRE` survives into every subsequent
 * request in the same worker, and an ordinary page load then gets handed a
 * JSON body it cannot render — the raw payload, printed on screen. Route name
 * is resolved per request by the router and cannot leak.
 */

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

// Livewire renames a custom update route so its name ENDS with
// `livewire.update`, which is why the middleware matches a wildcard. A probe
// route named the same way exercises the branch without standing up a real
// component snapshot.
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

    // `components: []` is what keeps this harmless for a client that never
    // registered the interceptor: Livewire iterates it looking for each
    // message's payload, finds none, and morphs nothing rather than throwing.
    expect($payload)->toHaveKey('components')
        ->and($payload['components'])->toBe([]);

    // Refused, not merely answered. Livewire runs persistent middleware
    // through a pipeline that stops for a RedirectResponse and discards
    // everything else, so an answer that is returned rather than thrown would
    // be served while the guarded work ran anyway.
    expect($response->getContent())->not->toContain('page body');
});

it('still redirects an ordinary locked page request', function () use ($armLock): void {
    $this->actingAs($armLock('lock-page-user'));

    // A browser navigating to a page wants a redirect, and changing that would
    // break every non-Livewire surface.
    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/help/data-locations')
        ->assertRedirect();
});

it('does not mistake a leaked X-Livewire header for a Livewire request', function () use ($armLock): void {
    $this->actingAs($armLock('lock-leak-user'));

    // This is the device failure, reproduced: an ordinary page load carrying a
    // stale X-Livewire header left over from an earlier Livewire POST in the
    // same persistent PHP worker. It must still redirect. Answering it with
    // JSON put the raw payload on screen as the whole page.
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

    // No lock config at all — the middleware must pass straight through.
    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get('/__lock-livewire-probe');

    $response->assertOk();

    expect($response->getContent())->not->toContain('beatraxLock')
        ->and($response->getContent())->toContain('page body');
});

it('keeps the client and the server naming the same payload key', function (): void {
    // The redirect travels in the body because that is the one part of the
    // answer no transport in this stack rewrites. If either side renames the
    // key the app silently goes back to being unrecoverable on a mid-request
    // lock, with nothing failing anywhere to say so.
    expect(file_get_contents(base_path('resources/js/lock.js')))
        ->toContain('beatraxLock');
});
