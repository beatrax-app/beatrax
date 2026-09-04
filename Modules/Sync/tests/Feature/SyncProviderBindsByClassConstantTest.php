<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

it('names every Sync class it binds by ::class, with no runtime-built name and no existence guard', function (): void {
    $path = base_path('Modules/Sync/Providers/SyncServiceProvider.php');
    $source = (string) file_get_contents($path);

    // A guard is load-bearing only where the class can genuinely be absent —
    // Modules/Mobile guards the nativephp facades because that package is
    // installed under mobile-app/vendor alone. Every name here is first-party
    // and reachable from both Composer roots, so each guard is dead code that
    // silently drops a binding the moment a namespace is mistyped.
    $guards = PatternScan::all('~(?:class|interface)_exists\s*\(~', $source);
    expect($guards[0])->toBe([], 'the provider guards a class that cannot be missing');

    $literals = PatternScan::all("~'((?:Modules|Native|Beatrax)\\\\\\\\[^']*)'~", $source);
    expect($literals[1])->toBe([], 'the provider builds a class name as a string instead of using ::class');

    expect($source)->not->toContain('singletonIfExists', 'the guarded-binding helper outlived its guards');
});

it('has every Sync class its provider imports on disk in this Composer root', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Sync/Providers/SyncServiceProvider.php'));

    $imports = PatternScan::all('~^use (Modules\\\\[A-Za-z0-9_\\\\]+);$~m', $source);
    expect($imports[1])->not->toBeEmpty();

    $missing = array_values(array_filter(
        $imports[1],
        static fn (string $fqcn): bool => ! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn),
    ));

    expect($missing)->toBe([], "The provider names classes this root cannot load:\n  ".implode("\n  ", $missing));
});
