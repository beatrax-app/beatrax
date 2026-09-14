<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// WebView.setWebContentsDebuggingEnabled is process-wide and independent of
// android:debuggable, so a release build answered chrome://inspect: live DOM
// and JS heap of the ledger, and script execution in the app's own origin, to
// anyone with adb on a borrowed phone.

function inspectionPatchScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_theme_native_shell.php';

    expect(is_file($script))->toBeTrue(sprintf('The patch script is not at %s.', $script));

    return $script;
}

function inspectionRoot(): string
{
    $root = sys_get_temp_dir().'/beatrax-inspection-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function inspectionShellPath(string $root): string
{
    return $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
}

// The shape the vendor ships: the debugging call is the last statement of
// configureWebViewSettings, indented eight spaces.
function inspectionWithShell(string $root): string
{
    mkdir(dirname(inspectionShellPath($root)), 0700, true);
    file_put_contents(inspectionShellPath($root), "class WebViewManager {\n"
        ."    private fun configureWebViewSettings() {\n"
        ."        webView.settings.apply {\n"
        ."            javaScriptEnabled = true\n"
        ."        }\n"
        ."\n"
        ."        WebView.setWebContentsDebuggingEnabled(true)\n"
        ."    }\n"
        ."}\n");

    return $root;
}

function runInspectionPatch(string $root): array
{
    $process = proc_open(
        ['php', inspectionPatchScript()],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['BEATRAX_NATIVE_ROOT' => $root, 'PATH' => getenv('PATH')],
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function patchedShell(string $root): string
{
    return (string) file_get_contents(inspectionShellPath($root));
}

it('leaves no unconditional enable in the shell it patches', function (): void {
    $root = inspectionWithShell(inspectionRoot());

    runInspectionPatch($root);

    // No message argument: toContain reads every argument as a needle, so an
    // explanation here becomes a second string the file must also not hold.
    expect(patchedShell($root))->not->toContain('setWebContentsDebuggingEnabled(true)');
});

it('gates the enable on the flag the platform sets for a debuggable build', function (): void {
    $root = inspectionWithShell(inspectionRoot());

    runInspectionPatch($root);

    expect(patchedShell($root))
        ->toContain('setWebContentsDebuggingEnabled(')
        ->toContain('FLAG_DEBUGGABLE');
});

// The positive control for the pair above. Deleting the call outright would
// satisfy both, and would also take the inspection a native:run build needs.
it('keeps the call, so a debuggable build still inspects', function (): void {
    $root = inspectionWithShell(inspectionRoot());

    runInspectionPatch($root);

    expect(substr_count(patchedShell($root), 'setWebContentsDebuggingEnabled'))->toBe(1);
});

// Both replacements come off the one anchor, and the second consumes it. A
// split that dropped either half would show here rather than on a device.
it('paints the background in the same pass', function (): void {
    $root = inspectionWithShell(inspectionRoot());

    runInspectionPatch($root);

    expect(patchedShell($root))->toContain('beatraxShellBackground');
});

it('is a no-op on a shell it has already patched', function (): void {
    $root = inspectionWithShell(inspectionRoot());

    runInspectionPatch($root);
    $once = patchedShell($root);

    runInspectionPatch($root);

    expect(patchedShell($root))->toBe($once);
});

/**
 * The anchor read out of the patch script rather than spelled again here. A
 * guard holding its own copy of the pattern it checks agrees with itself while
 * both drift off the thing they are about.
 */
function inspectionAnchor(): string
{
    $found = PatternScan::first(
        '/\$anchor = \'([^\']+)\';/',
        (string) file_get_contents(inspectionPatchScript())
    );

    expect($found)->not->toBe([], 'The patch script no longer assigns $anchor a single-quoted literal, so this file cannot read what it looks for.');

    return $found[1];
}

/** The upstream template the generated project is copied from, where it is installed */
function inspectionUpstreamShell(): ?string
{
    $relative = 'vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';

    foreach ([base_path($relative), base_path('mobile-app/'.$relative)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

// Every case above patches a shell this file wrote, with the anchor spelled the
// way the script expects, so all of them pass whatever the vendor ships. The
// patch skips on a missing anchor with a bare `return` after an echo, and
// nativephp_patch_all collects only a non-zero exit — so a template whose line
// moved leaves the gate unapplied, the build green, and the shipped APK
// answering chrome://inspect. This is the one case that would see it.
it('anchors against the shell the vendor actually ships', function (): void {
    $upstream = inspectionUpstreamShell();

    if ($upstream === null) {
        test()->markTestSkipped('nativephp/mobile is installed only under the mobile Composer root, so the shipped WebView manager is not here to anchor against.');
    }

    expect($upstream)->toContain(inspectionAnchor());
});
