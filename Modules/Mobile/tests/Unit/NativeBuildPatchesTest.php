<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Psr\Log\LoggerInterface;

/*
 * The patch scripts re-run immediately before a mobile build because the build
 * tooling regenerates the Android project, and a regenerated project kept
 * whatever the last composer run left behind — the camera and theme fixes
 * simply vanished from the APK.
 *
 * Two properties matter and both are about not making things worse: a patch
 * that fails degrades to the unpatched shell rather than aborting the build,
 * and a root that does not ship the scripts at all boots normally.
 *
 * Real subprocesses, not a mocked runner. Each script ends in exit(0), so the
 * reason they are spawned rather than required is that requiring one would end
 * the BUILD process with it — that is exactly what a fake would paper over.
 */

function buildPatchesDirectory(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-patches-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

function writePatchScript(string $dir, string $name, string $body): string
{
    $path = $dir.DIRECTORY_SEPARATOR.$name;
    file_put_contents($path, "<?php\n".$body."\n");

    return $path;
}

it('runs every patch script it finds', function (): void {
    $dir = buildPatchesDirectory();
    $marker = $dir.DIRECTORY_SEPARATOR.'ran.txt';

    writePatchScript($dir, 'nativephp_grant_webview_camera.php', 'file_put_contents('.var_export($marker, true).", 'camera'); exit(0);");
    writePatchScript($dir, 'nativephp_theme_native_shell.php', 'file_put_contents('.var_export($marker, true).", 'theme', FILE_APPEND); exit(0);");

    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldNotReceive('warning');

    (new NativeBuildPatches($log))->apply($dir);

    expect(file_get_contents($marker))->toBe('cameratheme');
});

// The whole reason each script is spawned rather than required: they end in
// exit(0), so requiring one would end the build process with it and nothing
// after the first patch would ever compile.
it('survives a script that exits, and keeps going', function (): void {
    $dir = buildPatchesDirectory();
    $marker = $dir.DIRECTORY_SEPARATOR.'later.txt';

    writePatchScript($dir, 'nativephp_grant_webview_camera.php', 'exit(0);');
    writePatchScript($dir, 'nativephp_extend_bundle_copy_timeout.php', 'file_put_contents('.var_export($marker, true).", 'reached'); exit(0);");

    (new NativeBuildPatches(Mockery::mock(LoggerInterface::class)))->apply($dir);

    expect(file_get_contents($marker))->toBe('reached');
});

it('logs a failing patch instead of aborting the build', function (): void {
    $dir = buildPatchesDirectory();
    $marker = $dir.DIRECTORY_SEPARATOR.'after-failure.txt';

    writePatchScript($dir, 'nativephp_grant_webview_camera.php', "fwrite(STDERR, 'patch exploded'); exit(1);");
    writePatchScript($dir, 'nativephp_keep_webview_cookies.php', 'file_put_contents('.var_export($marker, true).", 'still ran'); exit(0);");

    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('warning')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'patch script failed')
            && $context['script'] === 'nativephp_grant_webview_camera.php'
            && str_contains((string) $context['exception'], 'patch exploded'));

    (new NativeBuildPatches($log))->apply($dir);

    expect(file_get_contents($marker))->toBe('still ran');
});

// This provider also boots from roots that do not ship scripts/ at all, so a
// missing script is an ordinary state rather than a failure to report.
it('skips scripts the root does not ship, without logging', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldNotReceive('warning');

    (new NativeBuildPatches($log))->apply(buildPatchesDirectory());

    expect(true)->toBeTrue();
});
