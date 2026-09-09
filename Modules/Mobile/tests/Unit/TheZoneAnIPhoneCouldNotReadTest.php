<?php

declare(strict_types=1);
use Modules\Core\Public\Support\HostTimezone;

// HostTimezone::detect() asks four sources: an environment variable the shell
// may fill, /etc/localtime, /etc/timezone, and the Windows registry. Its
// comment claimed macOS, Linux and iOS all symlink /etc/localtime.
//
// Measured inside the app on an iPhone (iOS 26.5.2): link_exists false,
// is_link false, /etc/timezone false, TZ false, shell_env false, resolved UTC.
// The sandbox shows the app no /etc at all. The phone was on CEST and the
// application's log wrote 06:47 while the wall clock said 08:48 — two hours
// out, in the frame every DATETIME column is stored in.
//
// Development builds hide it: both .env files pin APP_TIMEZONE, so tier one
// wins and detect() never runs. .env.bundled omits it, which makes UTC the
// answer on every shipped iOS build.

const IOS_TIMEZONE_ANCHOR = '        setenv("PHPRC", phpIniPath, 1)';

function iosTimezoneApp(bool $withAnchor = true): string
{
    $seam = $withAnchor ? IOS_TIMEZONE_ANCHOR : '        // this site was rewritten upstream';

    return "import SwiftUI\n\n"
        ."struct NativePHPApp {\n"
        ."    private func preparePhpEnvironment() -> String {\n"
        ."        let phpIniPath = createPhpIni()\n\n"
        .$seam."\n\n"
        ."        setupEnvironment()\n"
        ."        return phpIniPath\n"
        ."    }\n}\n";
}

const IOS_RUNTIME_ANCHOR = '        setenv("NATIVEPHP_PLATFORM", "ios", 1)';

function iosTimezoneRuntime(bool $withAnchor = true): string
{
    $seam = $withAnchor ? IOS_RUNTIME_ANCHOR : '        // this site was rewritten upstream';

    return "import Foundation\n\n"
        ."final class PersistentPHPRuntime {\n"
        ."    private func boot() {\n"
        ."        setenv(\"NATIVEPHP_TEMPDIR\", tempDir, 1)\n"
        .$seam."\n"
        ."        setenv(\"REMOTE_ADDR\", \"0.0.0.0\", 1)\n"
        ."    }\n}\n";
}

function iosTimezoneScaffold(bool $withAnchor = true): string
{
    $root = sys_get_temp_dir().'/beatrax-ios-tz-'.bin2hex(random_bytes(6));

    $files = [
        'ios/NativePHP/NativePHPApp.swift' => iosTimezoneApp($withAnchor),
        'ios/NativePHP/Bridge/PersistentPHPRuntime.swift' => iosTimezoneRuntime($withAnchor),
    ];

    foreach ($files as $relative => $contents) {
        $full = $root.'/nativephp/'.$relative;
        mkdir(dirname($full), 0700, true);
        file_put_contents($full, $contents);
    }

    return $root;
}

function patchedIosTimezoneRuntime(string $root): string
{
    return (string) file_get_contents($root.'/nativephp/ios/NativePHP/Bridge/PersistentPHPRuntime.swift');
}

function patchedIosTimezoneApp(string $root): string
{
    return (string) file_get_contents($root.'/nativephp/ios/NativePHP/NativePHPApp.swift');
}

/** @return array{status: int, stdout: string, stderr: string} */
function runIosTimezonePatch(string $root): array
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_ios_host_timezone.php';

    expect(is_file($script))->toBeTrue("The patch script is not at {$script}.");

    $process = proc_open(
        ['php', $script],
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

it('supplies the zone the shell can read and PHP cannot', function (): void {
    $root = iosTimezoneScaffold();

    expect(runIosTimezonePatch($root)['status'])->toBe(0);

    expect(patchedIosTimezoneApp($root))
        ->toContain('setenv("BEATRAX_HOST_TIMEZONE", TimeZone.current.identifier, 1)');
});

// Before the interpreter boots, so the first request is already in the right
// frame rather than every request after it.
it('supplies it beside PHPRC, before the interpreter boots', function (): void {
    $root = iosTimezoneScaffold();
    runIosTimezonePatch($root);

    $patched = patchedIosTimezoneApp($root);

    expect(strpos($patched, 'BEATRAX_HOST_TIMEZONE'))
        ->toBeGreaterThan((int) strpos($patched, 'PHPRC'))
        ->and(strpos($patched, 'BEATRAX_HOST_TIMEZONE'))
        ->toBeLessThan((int) strpos($patched, 'setupEnvironment()'));
});

// The name the shell reads has to be the name PHP looks for; they are two
// halves of one contract with nothing but this test holding them together.
it('supplies the variable HostTimezone actually asks for', function (): void {
    $root = iosTimezoneScaffold();
    runIosTimezonePatch($root);

    expect(patchedIosTimezoneApp($root))
        ->toContain(HostTimezone::SUPPLIED_BY_THE_SHELL);
});

// ICU knows the offset and answers "CET", which listIdentifiers() does not
// contain, so isZone() rejects it and the floor answers anyway. The identifier
// the shell supplies has to survive that guard.
it('supplies a zone the guard accepts', function (): void {
    expect(HostTimezone::isZone('Europe/Amsterdam'))->toBeTrue()
        ->and(HostTimezone::isZone('CET'))->toBeFalse();
});

it('patches once however often the build regenerates the project', function (): void {
    $root = iosTimezoneScaffold();
    runIosTimezonePatch($root);
    $second = runIosTimezonePatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched');
});

it('fails loudly when the seam it writes into has moved', function (): void {
    $result = runIosTimezonePatch(iosTimezoneScaffold(withAnchor: false));

    expect($result['status'])->not->toBe(0)
        ->and($result['stderr'])->toContain('expected 1');
});

// Two runtimes boot PHP on iOS and each carries its own environment. Supplying
// only the first left the background scheduler in UTC: measured on the device,
// the same "BackgroundTasks: found 19 schedule event(s)" line was written at
// 08:55:46 and again at 06:55:58, twelve seconds apart and two hours apart.
it('supplies the zone to the background runtime as well as the request one', function (): void {
    $root = iosTimezoneScaffold();
    runIosTimezonePatch($root);

    expect(patchedIosTimezoneRuntime($root))
        ->toContain('setenv("BEATRAX_HOST_TIMEZONE", TimeZone.current.identifier, 1)');
});

// The fixture is written by hand. This ties the seam to the shell the build
// actually compiles.
it('anchors on text the shipped app really carries', function (): void {
    $candidate = base_path('mobile-app/nativephp/ios/NativePHP/NativePHPApp.swift');

    if (! is_file($candidate)) {
        expect(true)->toBeTrue();

        return;
    }

    expect(substr_count((string) file_get_contents($candidate), 'setenv("PHPRC", phpIniPath, 1)'))->toBe(1);

    $runtime = base_path('mobile-app/nativephp/ios/NativePHP/Bridge/PersistentPHPRuntime.swift');

    if (is_file($runtime)) {
        expect(substr_count((string) file_get_contents($runtime), 'setenv("NATIVEPHP_PLATFORM", "ios", 1)'))->toBe(1);
    }
});
