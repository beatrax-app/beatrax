<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * What a locked session says to a Livewire XHR.
 *
 * A 302 to the HTML lock page is the wrong answer to a request whose client
 * reads the body as JSON. On Android it was a fatal one: NativePHP's bridge
 * follows the redirect in-process, so `response.redirected` is false, the lock
 * page's HTML reached `JSON.parse`, and the app was left with the old
 * component and the new page half-mounted — lock screen painted as a narrow
 * inset column, then blank, then no request from any tap until a force-stop.
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

it('answers a locked Livewire request with a JSON body carrying the lock url', function () use ($armLock): void {
    $this->actingAs($armLock('lock-livewire-user'));

    $response = $this->withSession([LockStateManager::SESSION_KEY => true])
        ->withHeaders(['X-Livewire' => '1'])
        ->get('/help/data-locations');

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
});

it('refuses the request rather than merely answering it', function () use ($armLock): void {
    $this->actingAs($armLock('lock-livewire-refuse-user'));

    // The page's own body must not be in the answer. Livewire runs persistent
    // middleware through a pipeline that stops for a RedirectResponse and
    // discards everything else, so a lock answer that is returned instead of
    // thrown would be served while the guarded work ran anyway.
    $response = $this->withSession([LockStateManager::SESSION_KEY => true])
        ->withHeaders(['X-Livewire' => '1'])
        ->get('/help/data-locations');

    expect($response->getContent())->not->toContain('<html');
});

it('still redirects an ordinary locked page request', function () use ($armLock): void {
    $this->actingAs($armLock('lock-page-user'));

    // No X-Livewire header: a browser navigating to a page wants a redirect,
    // and changing that would break every non-Livewire surface.
    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/help/data-locations')
        ->assertRedirect();
});

it('leaves an unlocked Livewire request alone', function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'unlocked-livewire-user',
        'password' => bcrypt('unlocked-livewire-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // No lock config at all — the middleware must pass straight through, and
    // in particular must not hand a page request a JSON body.
    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->withHeaders(['X-Livewire' => '1'])
        ->get('/help/data-locations');

    $response->assertOk();

    expect($response->getContent())->not->toContain('beatraxLock');
});

it('keeps the client and the server naming the same payload key', function (): void {
    // The redirect travels in the body because that is the one part of the
    // answer no transport in this stack rewrites. If either side renames the
    // key the app silently goes back to being unrecoverable on a mid-request
    // lock, with nothing failing anywhere to say so.
    expect(file_get_contents(base_path('resources/js/lock.js')))
        ->toContain('beatraxLock');
});
