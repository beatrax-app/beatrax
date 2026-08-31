<?php

declare(strict_types=1);

// Each shell writes the whole php.ini itself, and the bundled interpreter's
// stock zend.exception_ignore_args is Off — so anything that renders a trace
// writes the first 15 characters of every string argument into the daily log.
// SafeTrace assembles its own frames and needs none of this; the directive is
// what covers the traces nothing in this repository formats.

/** @return array{status: int, stdout: string, stderr: string} */
function runShellIniPatch(string $script, string $root): array
{
    $path = dirname(__DIR__, 4).'/scripts/'.$script;

    expect(is_file($path))->toBeTrue("The patch script is not at {$path}.");

    $process = proc_open(
        ['php', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['BEATRAX_NATIVE_ROOT' => $root, 'PATH' => (string) getenv('PATH')],
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function exceptionArgsAndroidScaffold(): string
{
    $root = sys_get_temp_dir().'/beatrax-shell-ini-android-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/bridge', 0700, true);

    // Concatenated with an unindented nowdoc: the anchor is indentation-exact
    // and the ini lines sit at column zero inside a Kotlin raw string, so an
    // indented closing marker would strip the very columns being matched.
    $kotlin = "package com.nativephp.mobile.bridge\n\nclass LaravelEnvironment {\n"
        ."    fun setupEnvironment() {\n"
        .<<<'KOTLIN'
                val phpIni = """
curl.cainfo="${context.filesDir.absolutePath}/$CACERT_FILE"
openssl.cafile="${context.filesDir.absolutePath}/$CACERT_FILE"
"""
KOTLIN
        ."\n    }\n}\n";

    file_put_contents(exceptionArgsAndroidPath($root), $kotlin);

    return $root;
}

function exceptionArgsAndroidPath(string $root): string
{
    return $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt';
}

function exceptionArgsIosScaffold(): string
{
    $root = sys_get_temp_dir().'/beatrax-shell-ini-ios-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/ios/NativePHP/Bridge', 0700, true);

    // Concatenated rather than heredoc: the anchor is indentation-exact, and
    // an indented closing marker would strip the very spaces being matched.
    $swift = "    private func createPhpIni(caPath: String) -> String {\n"
        .<<<'SWIFT'
                let phpIni = """
                curl.cainfo="\(caPath)"
                openssl.cafile="\(caPath)"
                """
        SWIFT
        ."\n    }\n";

    foreach (exceptionArgsIosPaths($root) as $path) {
        file_put_contents($path, $swift);
    }

    return $root;
}

/** @return list<string> */
function exceptionArgsIosPaths(string $root): array
{
    return [
        $root.'/nativephp/ios/NativePHP/NativePHPApp.swift',
        $root.'/nativephp/ios/NativePHP/Bridge/PersistentPHPRuntime.swift',
    ];
}

it('writes the directive into the Android shell php.ini', function (): void {
    $root = exceptionArgsAndroidScaffold();

    expect(runShellIniPatch('nativephp_android_upload_limits.php', $root)['status'])->toBe(0);

    $patched = (string) file_get_contents(exceptionArgsAndroidPath($root));

    expect($patched)
        ->toContain('zend.exception_ignore_args=1')
        // The CA paths are why the file writes an ini at all.
        ->toContain('curl.cainfo="${context.filesDir.absolutePath}/$CACERT_FILE"');
});

it('writes the directive into both iOS php.ini writers', function (): void {
    $root = exceptionArgsIosScaffold();

    expect(runShellIniPatch('nativephp_ios_upload_limits.php', $root)['status'])->toBe(0);

    foreach (exceptionArgsIosPaths($root) as $path) {
        expect((string) file_get_contents($path))
            ->toContain('zend.exception_ignore_args=1')
            ->toContain('openssl.cafile="\(caPath)"');
    }
});

it('leaves one copy of the directive however often the build re-runs the patch', function (): void {
    $android = exceptionArgsAndroidScaffold();
    $ios = exceptionArgsIosScaffold();

    runShellIniPatch('nativephp_android_upload_limits.php', $android);
    runShellIniPatch('nativephp_android_upload_limits.php', $android);
    runShellIniPatch('nativephp_ios_upload_limits.php', $ios);
    runShellIniPatch('nativephp_ios_upload_limits.php', $ios);

    expect(substr_count((string) file_get_contents(exceptionArgsAndroidPath($android)), 'zend.exception_ignore_args='))->toBe(1);

    foreach (exceptionArgsIosPaths($ios) as $path) {
        expect(substr_count((string) file_get_contents($path), 'zend.exception_ignore_args='))->toBe(1);
    }
});
