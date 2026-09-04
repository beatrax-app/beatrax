<?php

declare(strict_types=1);

// Android WebView neither grants nor denies a getUserMedia() call without an
// onPermissionRequest override — the promise never settles — so the in-page QR
// scanner cannot start and falls back to the plugin's full-screen activity.
// Two files need the override: the generated shell, and the EDGE renderer in
// vendor/, which every build re-copies over the generated tree.

function cameraPatchScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_grant_webview_camera.php';

    expect(is_file($script))->toBeTrue("The patch script is not at {$script}.");

    return $script;
}

function runCameraPatch(string $root): array
{
    $process = proc_open(
        ['php', cameraPatchScript()],
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

function cameraRoot(): string
{
    $root = sys_get_temp_dir().'/beatrax-camera-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function cameraShellPath(string $root): string
{
    return $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
}

function cameraEdgePath(string $root): string
{
    return $root.'/vendor/nativephp/mobile-ui/resources/android/WebviewRenderer.kt';
}

function cameraWithShell(string $root, ?string $body = null): string
{
    mkdir(dirname(cameraShellPath($root)), 0700, true);
    file_put_contents(cameraShellPath($root), $body ?? "class WebViewManager {\n"
        ."    fun make(): WebChromeClient {\n"
        ."        return object : WebChromeClient() {\n"
        ."            override fun onShowCustomView() {}\n"
        ."        }\n"
        ."    }\n"
        ."}\n");

    return $root;
}

function cameraWithEdge(string $root, ?string $body = null): string
{
    mkdir(dirname(cameraEdgePath($root)), 0700, true);
    file_put_contents(cameraEdgePath($root), $body ?? "class WebviewRenderer {\n"
        ."    private class NoPopupChromeClient : WebChromeClient() {\n"
        ."        override fun onCreateWindow() = false\n"
        ."    }\n"
        ."}\n");

    return $root;
}

function cameraGrants(string $path): int
{
    return is_file($path) ? substr_count((string) file_get_contents($path), 'onPermissionRequest') : 0;
}

it('grants video capture in both the generated shell and the vendor renderer', function (): void {
    $root = cameraWithEdge(cameraWithShell(cameraRoot()));

    $result = runCameraPatch($root);

    expect($result['status'])->toBe(0)
        ->and(cameraGrants(cameraShellPath($root)))->toBeGreaterThan(0)
        ->and(cameraGrants(cameraEdgePath($root)))->toBe(1);
});

// The half that used to be lost. The generated tree is absent until
// native:install has run, but vendor/ is there after any composer install —
// and the vendor patch is the one that survives the next build.
it('patches the vendor renderer even when no Android scaffold has been generated', function (): void {
    $root = cameraWithEdge(cameraRoot());

    $result = runCameraPatch($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no Android scaffold')
        ->and(cameraGrants(cameraEdgePath($root)))->toBe(1);
});

it('patches the generated shell when vendor holds no renderer, and says the half was skipped', function (): void {
    $root = cameraWithShell(cameraRoot());

    $result = runCameraPatch($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no EDGE renderer')
        ->and(cameraGrants(cameraShellPath($root)))->toBeGreaterThan(0);
});

// The anchor survives its own patch, so a second run would append a second
// override and Kotlin refuses the duplicate method.
it('is idempotent, because native:install and composer both regenerate under it', function (): void {
    $root = cameraWithEdge(cameraWithShell(cameraRoot()));

    runCameraPatch($root);
    $shellOnce = (string) file_get_contents(cameraShellPath($root));
    $edgeOnce = (string) file_get_contents(cameraEdgePath($root));

    $again = runCameraPatch($root);

    expect($again['status'])->toBe(0)
        ->and((string) file_get_contents(cameraShellPath($root)))->toBe($shellOnce)
        ->and((string) file_get_contents(cameraEdgePath($root)))->toBe($edgeOnce)
        ->and(cameraGrants(cameraEdgePath($root)))->toBe(1)
        ->and($again['stdout'])->toContain('main shell already patched')
        ->and($again['stdout'])->toContain('EDGE renderer already grants');
});

it('fails loudly when the generated shell changed shape', function (): void {
    $root = cameraWithEdge(cameraWithShell(cameraRoot(), "class WebViewManager { fun make() = null }\n"));

    $result = runCameraPatch($root);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('WebChromeClient anchor not found');
});

it('fails loudly when the vendor renderer changed shape', function (): void {
    $root = cameraWithEdge(cameraWithShell(cameraRoot()), "class WebviewRenderer { }\n");

    $result = runCameraPatch($root);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('NoPopupChromeClient anchor not found');
});

it('skips a checkout that has neither', function (): void {
    $result = runCameraPatch(cameraRoot());

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no Android scaffold')
        ->and($result['stdout'])->toContain('no EDGE renderer');
});
