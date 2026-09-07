<?php

declare(strict_types=1);

// Apple's submission tooling refuses a Mach-O outside a Contents/MacOS
// directory, and the desktop bundle keeps its interpreter under Resources. The
// store lane has to move it, and the shell has to follow it there.

/** @return array{0: string, 1: string, 2: string} root, builder config, main script */
function relocateScaffold(?string $config = null, ?string $main = null): array
{
    $root = sys_get_temp_dir().'/beatrax-relocate-'.bin2hex(random_bytes(6));
    $electron = $root.'/nativephp/electron';

    mkdir($electron.'/src/main', 0o755, true);
    mkdir($root.'/scripts', 0o755, true);

    $vendor = base_path('vendor/nativephp/desktop/resources/electron');

    file_put_contents($electron.'/electron-builder.mjs', $config ?? (string) file_get_contents($vendor.'/electron-builder.mjs'));
    file_put_contents($electron.'/src/main/index.js', $main ?? (string) file_get_contents($vendor.'/src/main/index.js'));

    foreach (['nativephp_mac_app_store_lane.php', 'nativephp_interpreter_into_macos.php'] as $script) {
        copy(base_path('scripts/'.$script), $root.'/scripts/'.$script);
    }

    return [$root, $electron.'/electron-builder.mjs', $electron.'/src/main/index.js'];
}

/** @return array{0: int, 1: string} */
function relocateRun(string $root, string $script): array
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/'.$script).' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

it('puts the interpreter beside the app executable in the store lane only', function (): void {
    [$root, $config] = relocateScaffold();

    relocateRun($root, 'nativephp_mac_app_store_lane.php');
    [$code, $output] = relocateRun($root, 'nativephp_interpreter_into_macos.php');

    expect($code)->toBe(0, $output);

    $patched = (string) file_get_contents($config);
    $mas = (int) mb_strpos($patched, 'mas: {');

    expect($patched)->toContain("to: 'MacOS/php'");
    // Inside the mas block, not the mac one: moving a signed binary in the
    // notarised lane would invalidate a shipping product to fix a lane it is
    // not in.
    expect(mb_strpos($patched, "to: 'MacOS/php'"))->toBeGreaterThan($mas);
});

it('teaches the shell to prefer the relocated copy, and to fall back', function (): void {
    [$root, , $main] = relocateScaffold();

    relocateRun($root, 'nativephp_mac_app_store_lane.php');
    relocateRun($root, 'nativephp_interpreter_into_macos.php');

    $patched = (string) file_get_contents($main);

    expect($patched)->toContain('sandboxedInterpreter');
    expect($patched)->toContain('existsSync(sandboxedInterpreter)');
    // The fallback is what keeps the Developer ID build unchanged: there is no
    // php in its Contents/MacOS, so it resolves to the Resources copy.
    expect($patched)->toContain("path.join(buildPath, 'php', executable)");
    expect($patched)->toContain("from 'node:fs'");
});

// A require() in an ES module is a runtime error, and the generated file is
// one — so the import has to be added rather than the call inlined.
it('adds the fs import once, however many times it runs', function (): void {
    [$root, , $main] = relocateScaffold();

    relocateRun($root, 'nativephp_mac_app_store_lane.php');
    relocateRun($root, 'nativephp_interpreter_into_macos.php');
    $once = (string) file_get_contents($main);

    [$code] = relocateRun($root, 'nativephp_interpreter_into_macos.php');

    expect($code)->toBe(0);
    expect((string) file_get_contents($main))->toBe($once);
    expect(mb_substr_count($once, "from 'node:fs'"))->toBe(1);
});

// The ordering in config/nativephp.php is what guarantees the mas block is
// there to add to, and a lane that silently keeps the old layout produces a
// bundle refused at upload, weeks later, by a machine.
it('refuses to run before the lane it adds to exists', function (): void {
    [$root] = relocateScaffold();

    [$code, $output] = relocateRun($root, 'nativephp_interpreter_into_macos.php');

    expect($code)->toBe(1);
    expect($output)->toContain('no mas block');
});

it('fails loudly when the interpreter lookup is not the shape it patches', function (): void {
    $vendor = base_path('vendor/nativephp/desktop/resources/electron');
    $main = str_replace(
        "const phpBinary = path.join(buildPath, 'php', executable);",
        'const phpBinary = somewhereElse();',
        (string) file_get_contents($vendor.'/src/main/index.js'),
    );

    [$root] = relocateScaffold(null, $main);

    relocateRun($root, 'nativephp_mac_app_store_lane.php');
    [$code, $output] = relocateRun($root, 'nativephp_interpreter_into_macos.php');

    expect($code)->toBe(1);
    expect($output)->toContain('interpreter lookup is not the shape');
});

it('runs after the lane script, in the order the hooks are declared', function (): void {
    /** @var array<int, mixed> $hooks */
    $hooks = array_map(strval(...), config('nativephp.prebuild', []));

    $lane = array_search('php scripts/nativephp_mac_app_store_lane.php', $hooks, true);
    $move = array_search('php scripts/nativephp_interpreter_into_macos.php', $hooks, true);

    expect($lane)->not->toBeFalse();
    expect($move)->not->toBeFalse();
    expect($lane)->toBeLessThan($move);
});
