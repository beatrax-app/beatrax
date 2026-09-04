<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-middleware-registered-on-one-root-only
 */

/** @return list<string> */
function registeredCoreMiddleware(string $bootstrapPath): array
{
    $contents = (string) file_get_contents($bootstrapPath);

    $imports = PatternScan::all(
        '/use Modules\\\\Core\\\\Internal\\\\Http\\\\Middleware\\\\(\w+);/',
        $contents,
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

// The same failure one layer down: not a middleware missing from a root, but a
// middleware CONFIGURED on one root only. `beatrax_scheme` is written by
// `document.cookie`, so it arrives in plaintext and EncryptCookies drops it
// unless excepted. Excepting it on the desktop root alone left the phone
// rendering `light` on a dark device on every request after the first — proved
// on the Samsung with the cookie present and the response still saying light.
it('excepts the client-written scheme cookie from encryption on both roots', function (): void {
    foreach (['bootstrap/app.php', 'mobile-app/bootstrap/app.php'] as $root) {
        $contents = (string) file_get_contents(base_path($root));

        expect($contents)->toContain('encryptCookies(')
            ->and($contents)->toContain('AppChromeResolver::SCHEME_COOKIE');
    }
});
