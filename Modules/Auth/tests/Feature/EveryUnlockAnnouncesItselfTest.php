<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Public\Events\AppLockUnlocked;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

// An event that fires from some unlocks and not others is worse than none,
// because a listener written against it is correct only by luck. So the
// assertion is per path, and the last one closes the set.

function unlockEventUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('unlock-event-pass'),
        'period_start_day' => 1,
    ]);
}

/**
 * @return callable(): int
 */
function unlockEventCounter(): callable
{
    $heard = 0;

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->listen(AppLockUnlocked::class, function (AppLockUnlocked $event) use (&$heard): void {
        expect($event->session)->toBeInstanceOf(Session::class);
        $heard++;
    });

    // A full closure, not an arrow function: `fn` captures by value, so it
    // would answer with the zero that was in scope when it was built.
    return static function () use (&$heard): int {
        return $heard;
    };
}

it('announces the unlock that provisioning the lock performs', function (): void {
    $user = unlockEventUser('unlock-event-enable');
    $heard = unlockEventCounter();

    /** @var Session $session */
    $session = app(Session::class);

    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'unlock-event-pass', $session);

    expect($heard())->toBe(1);
});

it('announces the unlock a sign-in performs', function (): void {
    $user = unlockEventUser('unlock-event-login');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable((int) $user->id, '123456', 'unlock-event-pass', $session);

    $heard = unlockEventCounter();
    app(AppLockKeyService::class)->withhold($session);
    $provisioner->primeSessionAfterLogin((int) $user->id, 'unlock-event-pass', $session);

    expect($heard())->toBe(1);
});

// The ordinary unlock, and the one a dispatch from admitDataKey() would miss.
it('announces the unlock a lock-screen PIN performs', function (): void {
    $user = unlockEventUser('unlock-event-pin');

    /** @var Session $session */
    $session = app(Session::class);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'unlock-event-pass', $session);

    $heard = unlockEventCounter();
    app(AppLockKeyService::class)->withhold($session);

    expect(app(PinVerificationService::class)->verify((int) $user->id, '123456', $session))->not->toBeNull();
    expect($heard())->toBe(1);
});

it('announces the unlock an enclave recovery admits', function (): void {
    $heard = unlockEventCounter();

    /** @var Session $session */
    $session = app(Session::class);
    app(AppLockKeyService::class)->admitDataKey($session, str_repeat("\x2a", 32));

    expect($heard())->toBe(1);
});

it('stays silent when the session is locked rather than unlocked', function (): void {
    $heard = unlockEventCounter();

    /** @var Session $session */
    $session = app(Session::class);
    app(AppLockKeyService::class)->withhold($session);

    expect($heard())->toBe(0);
});

// What makes the four above the whole set: nothing else can put a key in the
// session, so no fifth path can exist without coming through the funnel.
it('has no writer of the held-key session entry outside LockStateManager', function (): void {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }
        if (str_ends_with($path, 'Internal/Lock/LockStateManager.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);
        if (preg_match('/->put\(\s*(?:LockStateManager::DATA_KEY_SESSION|\'beatrax_data_key\')/', $source) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([]);
});
