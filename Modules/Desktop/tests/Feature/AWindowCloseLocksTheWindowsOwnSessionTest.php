<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Native\Desktop\Events\Windows\WindowClosed;

// The sibling suites drive the listener directly and so cannot see the question
// this one asks: the shell does not call the listener, it posts an event to a
// route, and what that route can resolve as "the session" is the requirement.

// One store serves every request in-process, which is why a session-writing
// listener passed here for as long as it shipped. Persisting the window's
// session and reloading it from the handler is what puts the real boundary
// back: whatever the shell's own request left in memory is dropped, exactly as
// a request that never ran StartSession leaves it dropped in a build.
function windowSessionAcrossTheShellsRequest(Session $session, string $id): void
{
    $session->flush();
    $session->setId($id);
    $session->start();
}

function windowCloseLockUser(bool $lockEnabled): User
{
    $user = User::query()->create([
        'username' => 'window-close-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    if ($lockEnabled) {
        DB::connection()->table('user_app_lock_configs')->insert([
            'user_id' => $user->id,
            'lock_enabled' => true,
            'idle_timeout_minutes' => 30,
            'failed_attempts' => 0,
            'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    return $user;
}

function windowClosed(): void
{
    test()->post('_native/api/events', [
        'event' => WindowClosed::class,
        'payload' => ['main'],
    ])->assertOk();
}

it('starts no session on the route the shell posts to, and writes nothing to one', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(static fn ($r): bool => $r->uri() === '_native/api/events');

    expect($route)->not->toBeNull();

    // Pinned as absent on purpose. notifyLaravel() posts from the Electron main
    // process with no cookie jar, so StartSession here would open a fresh
    // anonymous store rather than the reader's -- adding it would bury this
    // defect rather than fix it.
    expect($route->gatherMiddleware())->not->toContain(StartSession::class);

    $this->actingAs(windowCloseLockUser(lockEnabled: true));

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    AppLockTestHarness::unlock($session, $dataKey);
    $session->save();

    windowClosed();

    expect($session->get(AppLockTestHarness::LOCKED_SESSION_KEY))->toBeFalse(
        'Nothing a shell event reaches may write a session: the store it '.
        'resolves belongs to no window, so a lock written there is decoration.',
    );

    sodium_memzero($dataKey);
});

it('locks the window session on the first request the window makes after the close', function (): void {
    $this->actingAs(windowCloseLockUser(lockEnabled: true));

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    AppLockTestHarness::unlock($session, $dataKey);
    $session->save();
    $windowSessionId = $session->getId();

    windowClosed();
    windowSessionAcrossTheShellsRequest($session, $windowSessionId);

    expect(AppLockTestHarness::isLocked($session))->toBeFalse(
        'Nothing the shell posted can have reached this session yet.',
    );

    $this->get(route('dashboard'))->assertRedirect(route('auth.lock'));

    expect(AppLockTestHarness::isLocked($session))->toBeTrue(
        'The window that closed still holds this session. Reopening the app '.
        'restores its cookie, so a close that left it unlocked hands the next '.
        'reader the ledger without a PIN.',
    );
    expect($session->get(AppLockTestHarness::HELD_KEY_SESSION_KEY))->toBeNull();

    sodium_memzero($dataKey);
});

it('holds the demand until somebody signs in, rather than spending it on the login screen', function (): void {
    $user = windowCloseLockUser(lockEnabled: true);

    windowClosed();

    $this->get(route('login'));

    $this->actingAs($user);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    AppLockTestHarness::unlock($session, $dataKey);

    $this->get(route('dashboard'))->assertRedirect(route('auth.lock'));

    expect(AppLockTestHarness::isLocked($session))->toBeTrue();

    sodium_memzero($dataKey);
});

it('leaves an account with no app lock alone, rather than dropping a key nothing hands back', function (): void {
    $this->actingAs(windowCloseLockUser(lockEnabled: false));

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $dataKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    AppLockTestHarness::unlock($session, $dataKey);
    $session->save();
    $windowSessionId = $session->getId();

    windowClosed();
    windowSessionAcrossTheShellsRequest($session, $windowSessionId);

    $this->get(route('dashboard'));

    expect(AppLockTestHarness::isLocked($session))->toBeFalse();
    expect($session->get(AppLockTestHarness::HELD_KEY_SESSION_KEY))->not->toBeNull();

    sodium_memzero($dataKey);
});
