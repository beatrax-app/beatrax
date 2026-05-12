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
