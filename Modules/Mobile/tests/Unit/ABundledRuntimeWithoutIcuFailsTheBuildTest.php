<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// Both composer roots declare ext-intl, but composer resolves that against the
// build host. Which runtime reaches the phone is decided by one flag, recorded
// in nativephp.lock, and read back by nothing — so a bare `native:install`
// swapped in a runtime with no intl and the first reader got the 500 that the
// ICU-less fallbacks were written to prevent but cannot catch.

// The mobile-app job runs this suite with base_path() at mobile-app/, where
// scripts/ is one level up; from the repo root it is alongside.
function icuRuntimeGuardScript(): string
{
    foreach ([base_path('scripts'), base_path('../scripts')] as $directory) {
        $candidate = $directory.'/nativephp_require_icu_runtime.php';

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('nativephp_require_icu_runtime.php is not reachable from '.base_path());
}

function runIcuRuntimeGuard(?string $lockContents): Process
{
    $root = sys_get_temp_dir().'/bx-icu-'.bin2hex(random_bytes(6));
    mkdir($root, 0755, true);

    if ($lockContents !== null) {
        file_put_contents($root.'/nativephp.lock', $lockContents);
    }

    $process = new Process(
        [PHP_BINARY, icuRuntimeGuardScript()],
        env: ['BEATRAX_NATIVE_ROOT' => $root],
    );

    $process->run();

    if ($lockContents !== null) {
        unlink($root.'/nativephp.lock');
    }

    rmdir($root);

    return $process;
}

it('accepts a runtime whose lock records ICU', function (): void {
    $process = runIcuRuntimeGuard('{"php":{"version":"8.5.10","icu":true}}');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('carries ICU');
});

it('refuses a runtime whose lock records no ICU, naming the flag that fixes it', function (): void {
    $process = runIcuRuntimeGuard('{"php":{"version":"8.5.10","icu":false}}');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('carries no ICU')
        ->and($process->getErrorOutput())->toContain('--with-icu');
});

// The flag is absent rather than false in a lock written by an older installer,
// which is the same runtime and must be refused the same way.
it('refuses a lock that does not record ICU at all', function (): void {
    $process = runIcuRuntimeGuard('{"php":{"version":"8.5.10"}}');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('carries no ICU');
});

// Nothing installed is nothing to ship; native:install runs before this script
// in composer's post-update-cmd.
it('passes when no runtime has been installed yet', function (): void {
    $process = runIcuRuntimeGuard(null);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('nothing installed');
});
