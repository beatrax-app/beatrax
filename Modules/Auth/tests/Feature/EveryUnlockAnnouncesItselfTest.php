<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
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

    expect(app(PinVerificationService::class)->verify((int) $user->id, '123456', $session)->dataKey)->not->toBeNull();
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

// The one file allowed to name the key without owning it: it re-exports the
// constant for the suite and writes nothing.
const HELD_KEY_NAMED_ELSEWHERE = ['Modules/Auth/Public/Testing/AppLockTestHarness.php'];

/**
 * Every file outside LockStateManager that names the held-key session entry,
 * by the constant or by its value.
 *
 * @return array{offenders: list<string>, walked: int, owner: bool}
 */
function heldKeySessionNamers(bool $excludeOwner): array
{
    $key = LockStateManager::DATA_KEY_SESSION;
    $offenders = [];
    $walked = 0;
    $owner = false;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $walked++;
        $isOwner = str_ends_with($path, 'Internal/Lock/LockStateManager.php');
        $source = (string) file_get_contents($path);

        // The VALUE as well as the constant. A second writer does not have to
        // name DATA_KEY_SESSION at all — a local const holding the same string
        // reaches the same session entry — and a session key has no other
        // legitimate use, so naming it anywhere is the offence.
        if (! str_contains($source, $key) && ! str_contains($source, 'DATA_KEY_SESSION')) {
            continue;
        }

        if ($isOwner) {
            $owner = true;

            if ($excludeOwner) {
                continue;
            }
        }

        $offenders[] = str_replace(base_path().'/', '', $path);
    }

    sort($offenders);

    return ['offenders' => $offenders, 'walked' => $walked, 'owner' => $owner];
}

// What makes the four above the whole set: nothing else can put a key in the
// session, so no fifth path can exist without coming through the funnel.
//
// Read as a value rather than as a spelling of the write. The rule used to
// match `->put(` beside the constant, and three ordinary re-spellings walked
// past it: the Session facade writes `::put`, a local constant holding the
// same string never names DATA_KEY_SESSION, and a named argument puts `key:`
// between the two. All three were planted at once and it stayed green.
it('has no writer of the held-key session entry outside LockStateManager', function (): void {
    $read = heldKeySessionNamers(excludeOwner: true);

    // A walk that opened nothing, or a detector that stopped matching, reports
    // the same empty list a closed funnel does.
    expect($read['walked'])->toBeGreaterThan(
        2_000,
        'The walk opened '.$read['walked'].' files, too few to be this tree.',
    );

    expect($read['owner'])->toBeTrue(
        'LockStateManager itself was not recognised as naming the key it owns, so the reader below is matching nothing.',
    );

    expect(array_values(array_diff($read['offenders'], HELD_KEY_NAMED_ELSEWHERE)))->toBe([], implode("\n  ", [
        'These name the held-key session entry outside the one class that owns it, so the four '
        .'unlock paths above are no longer the whole set:',
        ...array_values(array_diff($read['offenders'], HELD_KEY_NAMED_ELSEWHERE)),
    ]));
});

it('still recognises the owner when it is not excused', function (): void {
    // The positive control for the arm above: the detector has to find the one
    // file that legitimately names the key, or finding nobody else means
    // nothing at all.
    expect(heldKeySessionNamers(excludeOwner: false)['offenders'])
        ->toContain('Modules/Auth/Internal/Lock/LockStateManager.php');
});
