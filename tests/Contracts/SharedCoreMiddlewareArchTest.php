<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-middleware-registered-on-one-root-only
 */

/**
 * The Core middleware a bootstrap file puts on the stack, read from the source
 * rather than from a booted kernel: only one of the two roots boots in any
 * given run, and the missing one is the whole subject of this rule.
 *
 * @return list<string>
 */
function coreMiddlewareRegisteredIn(string $source): array
{
    $imports = PatternScan::all(
        '/use Modules\\\\Core\\\\Internal\\\\Http\\\\Middleware\\\\(\w+);/',
        $source,
    );

    // An import alone proves nothing — the class has to reach the stack as
    // `X::class`, which is how every registration call spells it.
    $registered = array_filter(
        $imports[1],
        static fn (string $class): bool => str_contains($source, $class.'::class'),
    );

    sort($registered);

    return array_values($registered);
}

/** @return list<string> */
function registeredCoreMiddleware(string $bootstrapPath): array
{
    return coreMiddlewareRegisteredIn((string) file_get_contents($bootstrapPath));
}

it('registers every shared Core middleware on both roots', function (): void {
    $desktop = registeredCoreMiddleware(base_path('bootstrap/app.php'));
    $mobile = registeredCoreMiddleware(base_path('mobile-app/bootstrap/app.php'));

    expect(count($desktop))->toBeGreaterThan(
        1,
        'The desktop root registers almost no Core middleware, which is what a broken read of '
        .'bootstrap/app.php looks like rather than a stack somebody shrank. Every comparison below '
        .'is made against this list, so an empty one reports two roots in step over nothing.'
    );

    // Both directions. A middleware the phone registers and the desktop does
    // not is the same defect wearing the other mask, and reading one diff made
    // that half of the claim something nothing checked.
    $missing = [
        ...array_map(static fn (string $name): string => 'mobile-app/bootstrap/app.php is missing '.$name, array_diff($desktop, $mobile)),
        ...array_map(static fn (string $name): string => 'bootstrap/app.php is missing '.$name, array_diff($mobile, $desktop)),
    ];

    sort($missing);

    expect($missing)->toBe(
        [],
        "The two roots do not register the same Core middleware:\n  ".
        implode("\n  ", $missing)."\n".
        'A middleware absent on one root is dead on that platform with no error — see SetLocale.',
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

        expect(str_contains($contents, 'encryptCookies('))->toBeTrue(
            $root.' configures no cookie-encryption exception at all, so the scheme cookie is encrypted '
            .'on arrival and read back as absent — a dark device renders light on every request after the first.'
        );

        expect(str_contains($contents, 'AppChromeResolver::SCHEME_COOKIE'))->toBeTrue(
            $root.' excepts some cookie from encryption but not the client-written scheme one. It is set by '
            .'document.cookie, so it can never arrive encrypted and EncryptCookies drops it silently.'
        );
    }
});

// A guard whose every verdict is "this list is empty" passes when its reader
// stops reading. This drives the same function the rule above drives, over a
// bootstrap file written to hold each shape.
it('reads a middleware onto the stack only where one is registered', function (): void {
    // The private segment is assembled rather than written out.
    // pinnedCrossModuleInternalImports scans this file too, and reads an inline
    // Modules\<X>\Internal\ reference as a boundary crossing nobody pinned --
    // a nowdoc body included, because the scan reads text and not syntax.
    $private = 'Internal';
    $import = static fn (string $module, string $class): string => "use Modules\\{$module}\\{$private}\\Http\\Middleware\\{$class};";

    $source = implode("\n", [
        '<?php',
        $import('Core', 'SetLocale'),
        $import('Core', 'ImportedButNeverRegistered'),
        $import('Other', 'NotCore'),
        'return Application::configure()->withMiddleware(function (Middleware $middleware): void {',
        '    $middleware->append(SetLocale::class);',
        '    $middleware->append(NotCore::class);',
        '});',
    ]);

    expect(coreMiddlewareRegisteredIn($source))->toBe(
        ['SetLocale'],
        'The reader has to take the middleware that is imported AND put on the stack, and only those: '
        .'an import nothing registers is dead code, and a neighbouring module\'s middleware is not this '
        .'rule\'s business.'
    );
});
