<?php

declare(strict_types=1);

/*
 * Every shared Core middleware the desktop root registers must also be
 * registered by the mobile root.
 *
 * The sibling-root topology gives `mobile-app/` its own `bootstrap/app.php`
 * — a real file, never a symlink, because `dirname(__DIR__)` has to resolve
 * to the mobile root. Nothing keeps the two middleware stacks in step, and a
 * middleware added to one root is invisible in the other.
 *
 * It fails silently. `SetLocale` was never registered on mobile, so the
 * translator stayed on `config('app.locale')`: the pre-auth switcher wrote
 * `session('locale')` on every tap and nothing ever read it back. The route,
 * the negotiator and the session all worked, every test passed, and the
 * control was simply dead on device.
 *
 * Module-owned gates are deliberately NOT compared — `EnsureDatabaseReady`
 * and `MobileEnsureDatabaseReady` are different middleware for different
 * first-launch surfaces. Only `Modules\Core\Internal\Http\Middleware` is
 * shared surface, so only that namespace is held in common.
 */

/** @return list<string> */
function registeredCoreMiddleware(string $bootstrapPath): array
{
    $contents = (string) file_get_contents($bootstrapPath);

    preg_match_all(
        '/use Modules\\\\Core\\\\Internal\\\\Http\\\\Middleware\\\\(\w+);/',
        $contents,
        $imports,
    );

    // An import alone proves nothing — the class has to reach the stack as
    // `X::class`, which is how every registration call spells it.
    $registered = array_filter(
        $imports[1],
        static fn (string $class): bool => str_contains($contents, $class.'::class'),
    );

    sort($registered);

    return array_values($registered);
}

it('registers every shared Core middleware on both roots', function (): void {
    $desktop = registeredCoreMiddleware(base_path('bootstrap/app.php'));
    $mobile = registeredCoreMiddleware(base_path('mobile-app/bootstrap/app.php'));

    expect($desktop)->not->toBeEmpty();

    $missing = array_values(array_diff($desktop, $mobile));

    expect($missing)->toBe(
        [],
        "mobile-app/bootstrap/app.php is missing Core middleware the desktop root registers:\n  ".
        implode("\n  ", $missing)."\n".
        'A middleware absent here is dead on device with no error — see SetLocale.',
    );
});

it('keeps the locale middleware on the mobile stack', function (): void {
    // Named explicitly because it is the one this test was written for, and
    // a future refactor could drop it from both roots and stay "in step".
    expect(registeredCoreMiddleware(base_path('mobile-app/bootstrap/app.php')))
        ->toContain('SetLocale');
});
