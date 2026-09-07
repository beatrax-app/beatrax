<?php

declare(strict_types=1);

// The patch is a brace-matching insertion into a generated file nobody in this
// repo writes. A vendor upgrade that reshapes the mac block would make it a
// no-op, and the symptom would be a store submission signed against the
// Developer ID entitlements — which the store refuses.

function masLaneVendorConfig(): string
{
    $path = base_path('vendor/nativephp/desktop/resources/electron/electron-builder.mjs');

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array{0: string, 1: string} the fake project root and the config inside it */
function masLaneScaffold(string $config): array
{
    $root = sys_get_temp_dir().'/beatrax-maslane-'.bin2hex(random_bytes(6));
    $electron = $root.'/nativephp/electron';

    mkdir($electron, 0o755, true);
    mkdir($root.'/scripts', 0o755, true);

    file_put_contents($electron.'/electron-builder.mjs', $config);
    copy(base_path('scripts/nativephp_mac_app_store_lane.php'), $root.'/scripts/nativephp_mac_app_store_lane.php');

    return [$root, $electron.'/electron-builder.mjs'];
}

/** @return array{0: int, 1: string} */
function masLaneRun(string $root): array
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/nativephp_mac_app_store_lane.php').' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

it('finds the vendored config it is written against', function (): void {
    expect(masLaneVendorConfig())->toContain('mac: {');
});

it('adds a mas target to the config NativePHP actually ships', function (): void {
    [$root, $path] = masLaneScaffold(masLaneVendorConfig());

    [$code, $output] = masLaneRun($root);
    $patched = (string) file_get_contents($path);

    expect($code)->toBe(0, $output);
    expect($patched)->toContain('mas: {');
    expect($patched)->toContain("entitlements: 'build/entitlements.mas.plist'");
    expect($patched)->toContain("entitlementsInherit: 'build/entitlements.mas.inherit.plist'");
});

// Hardening a mas build restricts the writable-executable memory PCRE's JIT
// uses without granting the entitlement that would allow it, and the measured
// sandbox run needs neither.
it('does not harden the store lane', function (): void {
    [$root, $path] = masLaneScaffold(masLaneVendorConfig());
    masLaneRun($root);

    expect((string) file_get_contents($path))->toContain('hardenedRuntime: false');
});

// The mac block contains a nested object, so a regex that stops at the first
// inner brace lands the new block INSIDE the old one.
it('closes the mac block before it opens the store one', function (): void {
    [$root, $path] = masLaneScaffold(masLaneVendorConfig());
    masLaneRun($root);

    $patched = (string) file_get_contents($path);
    $mac = mb_strpos($patched, 'mac: {');
    $mas = mb_strpos($patched, 'mas: {');

    expect($mac)->toBeLessThan($mas);
    expect(mb_substr($patched, (int) $mac, (int) $mas - (int) $mac))->toContain('NSDownloadsFolderUsageDescription');
});

it('leaves a config that already has a mas block alone', function (): void {
    [$root, $path] = masLaneScaffold(masLaneVendorConfig());

    masLaneRun($root);
    $once = (string) file_get_contents($path);

    [$code] = masLaneRun($root);

    expect($code)->toBe(0);
    expect((string) file_get_contents($path))->toBe($once);
});

it('fails loudly when there is no mac block to sit beside', function (): void {
    [$root] = masLaneScaffold(str_replace('mac: {', 'macintosh: {', masLaneVendorConfig()));

    [$code, $output] = masLaneRun($root);

    expect($code)->toBe(1);
    expect($output)->toContain('could not find where the mac block ends');
});

it('runs before every build', function (): void {
    /** @var array<int, mixed> $hooks */
    $hooks = config('nativephp.prebuild', []);

    expect(implode("\n", array_map(strval(...), $hooks)))
        ->toContain('nativephp_mac_app_store_lane.php');
});
