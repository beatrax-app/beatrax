<?php

declare(strict_types=1);

it('composer.json does not require ext-imap', function (): void {
    $raw = file_get_contents(base_path('composer.json'));
    expect($raw)->not->toContain('"ext-imap"');

    $composer = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
    expect($require)->not->toHaveKey('ext-imap');
});

it('no PHP source under Modules/ or app/ extension-checks for ext-imap', function (): void {
    $cmd = sprintf(
        "grep -RIn --include='*.php' \"extension_loaded('imap')\" %s %s",
        escapeshellarg(base_path('Modules')),
        escapeshellarg(base_path('app')),
    );
    exec($cmd, $out, $code);
    expect($out)->toBeEmpty();
});

it('composer.lock contains no webklex/php-imap, webklex/laravel-imap, or ddeboer/imap packages', function (): void {
    // Belt-and-braces alongside the top-level composer.json "conflict"
    // block. The conflict block hard-fails composer install if any of
    // these packages enters the dependency graph; this lockfile grep
    // adds a CI-side fingerprint check that would catch a manual
    // composer.lock edit or a forced override that slipped past the
    // resolver.
    $lockPath = base_path('composer.lock');
    if (! is_file($lockPath)) {
        // Fresh checkouts without a locked install file pass
        // trivially — same skip predicate posture as other contract
        // tests that depend on installed dependencies.
        expect(true)->toBeTrue();

        return;
    }

    $contents = (string) file_get_contents($lockPath);
    $banned = [
        'webklex/laravel-imap',
        'webklex/php-imap',
        'ddeboer/imap',
    ];

    $hits = [];
    foreach ($banned as $package) {
        if (str_contains($contents, "\"{$package}\"")) {
            $hits[] = $package;
        }
    }

    expect($hits)->toBe(
        [],
        "composer.lock must not reference deprecated IMAP packages. Offenders:\n  ".implode("\n  ", $hits),
    );
});
