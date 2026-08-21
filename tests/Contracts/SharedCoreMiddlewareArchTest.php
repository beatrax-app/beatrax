<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-middleware-registered-on-one-root-only
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
