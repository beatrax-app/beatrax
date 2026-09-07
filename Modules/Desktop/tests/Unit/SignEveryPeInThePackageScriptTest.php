<?php

declare(strict_types=1);

// The patch is a regex against a generated file nobody in this repo writes.
// A vendor upgrade that reshapes the win block would make it a no-op, and the
// only symptom would be a store rejection months later — so the anchor is
// tested against the real vendored config rather than against a fixture that
// agrees with it by construction.

function signEveryPeVendorConfig(): string
{
    $path = base_path('vendor/nativephp/desktop/resources/electron/electron-builder.mjs');

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array{0: string, 1: string} the fake project root and the config inside it */
function signEveryPeScaffold(string $config): array
{
    $root = sys_get_temp_dir().'/beatrax-signexts-'.bin2hex(random_bytes(6));
    $electron = $root.'/nativephp/electron';

    mkdir($electron, 0o755, true);
    mkdir($root.'/scripts', 0o755, true);

    file_put_contents($electron.'/electron-builder.mjs', $config);
    copy(base_path('scripts/nativephp_sign_every_pe_in_the_package.php'), $root.'/scripts/nativephp_sign_every_pe_in_the_package.php');

    return [$root, $electron.'/electron-builder.mjs'];
}

/** @return array{0: int, 1: string} exit code and combined output */
function signEveryPeRun(string $root): array
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/nativephp_sign_every_pe_in_the_package.php').' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

it('finds the vendored config it is written against', function (): void {
    expect(signEveryPeVendorConfig())->toContain('win: {');
});

it('patches the config NativePHP actually ships', function (): void {
    [$root, $path] = signEveryPeScaffold(signEveryPeVendorConfig());

    [$code, $output] = signEveryPeRun($root);

    expect($code)->toBe(0, $output);
    expect((string) file_get_contents($path))->toContain("signExts: ['.exe', '.dll', '.node']");
});

it('leaves a config that already names signExts alone', function (): void {
    [$root, $path] = signEveryPeScaffold(signEveryPeVendorConfig());

    signEveryPeRun($root);
    $once = (string) file_get_contents($path);

    [$code, $output] = signEveryPeRun($root);

    expect($code)->toBe(0, $output);
    expect((string) file_get_contents($path))->toBe($once);
});

// A patch that cannot find its anchor and exits 0 ships an unsigned runtime
// with a build log that reads like a success.
it('fails loudly when the win block no longer has the shape it patches', function (): void {
    $reshaped = str_replace('executableName: fileName,', 'executableName: someOtherName,', signEveryPeVendorConfig());

    [$root] = signEveryPeScaffold($reshaped);

    [$code, $output] = signEveryPeRun($root);

    expect($code)->toBe(1);
    expect($output)->toContain('could not locate');
});
