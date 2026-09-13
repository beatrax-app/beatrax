<?php

declare(strict_types=1);

use App\Providers\NativeServiceProvider;
use Beatrax\BiometricVault\BiometricVaultServiceProvider;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Providers\BiometricsServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ScannerServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;
use Tests\Contracts\Support\StringNamedClasses;

// The plugin providers install only in mobile-app/vendor, so the manifest is
// checked as source text rather than through autoloading. plugins() itself can
// still be called: SomeClass::class is a compile-time literal in PHP and never
// triggers an autoload, even for a class absent from this root's vendor tree.

it('registers NativeServiceProvider in the mobile provider manifest (mobile-app/bootstrap/providers.php)', function (): void {
    $manifest = (string) file_get_contents(base_path('mobile-app/bootstrap/providers.php'));

    expect($manifest)->toContain('use App\Providers\NativeServiceProvider;');
    expect($manifest)->toContain('NativeServiceProvider::class');
    // Both manifest paths resolve relative to the repo root. Run from the
    // mobile-app root they resolve to mobile-app/mobile-app/… instead, describing
    // a tree that is not the one under test.
})->group('repo-root-only');

it('does NOT register NativeServiceProvider in the desktop provider manifest (bootstrap/providers.php)', function (): void {
    $manifest = (string) file_get_contents(base_path('bootstrap/providers.php'));

    expect($manifest)->not->toContain('NativeServiceProvider');
})->group('repo-root-only');

it('NativeServiceProvider::plugins() lists all 8 registered NativePHP mobile plugin providers', function (): void {
    $provider = new NativeServiceProvider(app());

    $plugins = $provider->plugins();

    expect($plugins)->toBe([
        BiometricsServiceProvider::class,
        ScannerServiceProvider::class,
        BackgroundTasksServiceProvider::class,
        NetworkServiceProvider::class,
        SecureStorageServiceProvider::class,
        LocalNotificationsServiceProvider::class,
        BiometricVaultServiceProvider::class,
        NativeUIServiceProvider::class,
    ]);
});

it('NativeServiceProvider.php source references the 8 plugin FQCNs verbatim (belt-and-suspenders on the compiled-build source)', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Mobile/Providers/NativePhpContract/NativeServiceProvider.php'));

    foreach ([
        'Native\Mobile\Providers\BiometricsServiceProvider',
        'Native\Mobile\Providers\ScannerServiceProvider',
        'NativePHP\BackgroundTasks\BackgroundTasksServiceProvider',
        'Native\Mobile\Providers\NetworkServiceProvider',
        'Native\Mobile\Providers\SecureStorageServiceProvider',
        'NativePHP\LocalNotifications\LocalNotificationsServiceProvider',
        'Beatrax\BiometricVault\BiometricVaultServiceProvider',
        'Native\Mobile\UI\NativeUIServiceProvider',
    ] as $expectedFqcn) {
        expect($source)->toContain($expectedFqcn);
    }
});

// nativephp/mobile resolves the plugin allow-list by `class_exists()` and `new`
// on the LITERAL string 'App\\Providers\\NativeServiceProvider'
// (Plugins/PluginDiscovery.php, lines 89 and 138). A miss is not an error there:
// it returns an empty allow-list — "block all plugins for security" — with no
// exception and no log line.
//
// Measured when this class was renamed to Modules\Mobile\Providers\*: the
// native element registry went from 54 types to 25. Everything a UI plugin
// registers disappeared — webview, button, toggle, modal, every form control —
// and the app shell rendered its chrome around an empty column. The desktop
// root noticed nothing; only the mobile Composer root can see it at all.
it('keeps the class name nativephp hard-codes, whichever root is asking', function (): void {
    expect(class_exists('App\\Providers\\NativeServiceProvider'))->toBeTrue(
        'nativephp/mobile looks this class up by literal name and silently blocks every plugin when it misses.'
    );

    // And by a psr-4 root that is not a repository-root app/ directory: the
    // namespace is the vendor's contract, the directory is ours.
    /** @var array{autoload: array{psr-4: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['autoload']['psr-4'])->toHaveKey('App\\Providers\\')
        ->and($composer['autoload']['psr-4']['App\\Providers\\'])->not->toStartWith('app/');
})->group('repo-root-only');

// The live registry, not a source scan: the failure is a lookup that answers
// with silence, and only the artefact it builds shows whether it answered.
it('registers the element types the UI plugins contribute', function (): void {
    if (! class_exists(ElementRegistry::class)) {
        test()->markTestSkipped('nativephp/mobile is installed only under mobile-app/vendor, so there is no registry to read from this root.');
    }

    $types = array_keys(ElementRegistry::all());

    // Named one by one rather than counted: a floor moves with every vendor
    // release, and what actually breaks is a specific element going missing.
    // webview is the app shell's whole body; the rest are the form surface.
    $fromPlugins = ['webview', 'button', 'toggle', 'modal', 'select', 'slider'];

    $missing = array_values(array_diff($fromPlugins, $types));

    expect($missing)->toBe([], sprintf(
        'Plugin discovery registered nothing for: %s. The registry holds %d types. '
        .'That is what an empty allow-list looks like — check that App\\Providers\\NativeServiceProvider still resolves.',
        implode(', ', $missing),
        count($types),
    ));
});

// The desktop root cannot resolve these and asks by string because of it, so
// tests/Contracts/AClassNamedAsAStringStillResolvesSomewhereArchTest can only
// check the spelling against a declared list. Here the answer is knowable, and
// it is asked of the SAME scan of the source rather than a copy of the list.
it('resolves every string-named class exactly where its package is', function (): void {
    $mobilePackageInstalled = class_exists(ElementRegistry::class);

    $wrong = [];
    foreach (array_keys(StringNamedClasses::lookups()) as $name) {
        // Names this root supplies either way are not the question.
        if (class_exists($name) && ! str_starts_with($name, 'Native') && ! str_starts_with($name, 'Beatrax\\Biometric')) {
            continue;
        }

        if (class_exists($name) !== $mobilePackageInstalled) {
            $wrong[] = $name;
        }
    }

    expect($wrong)->toBe([], sprintf(
        'nativephp/mobile is %s here, so these should be too and are not: %s',
        $mobilePackageInstalled ? 'installed' : 'absent',
        implode(', ', $wrong),
    ));
});
