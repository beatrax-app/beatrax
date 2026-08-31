<?php

declare(strict_types=1);

// The window is edge-to-edge, so the app paints the pixels behind the status
// and navigation bars while Android draws the clock and the nav glyphs on top
// of them -- taking their polarity from the OS night-mode setting, not from the
// app. Measured on a Galaxy S24 Ultra with the app on Light and the phone on
// dark: white glyphs on the app's own #f8fafc at 1.05:1, so the clock was
// invisible on every screen. `theme-color` cannot reach it; a WebView has no
// browser chrome for it to colour.

function systemBarScaffold(bool $withBridge = true): string
{
    $root = sys_get_temp_dir().'/beatrax-sysbar-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/ui', 0700, true);

    // Indentation-exact, like the anchor: the patch matches the class
    // declaration at its real four spaces, and a reflowed copy would not.
    $bridge = $withBridge
        ? "    inner class AndroidBridge {\n"
            ."        @android.webkit.JavascriptInterface\n"
            ."        fun openDrawer() {\n"
            ."        }\n"
            ."    }\n"
        : "    inner class SomethingElse {\n    }\n";

    // The branch that re-derives both flags from the OS, verbatim: it runs at
    // startup and again on every night-mode change, so it is the seam that has
    // to prefer the page's answer rather than overwrite it.
    $configure = "    private fun configureStatusBar() {\n"
        .'        when (statusBarStyle) {'."\n"
        .'            "auto" -> {'."\n"
        ."                val isSystemDarkMode = (resources.configuration.uiMode and\n"
        ."                    Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES\n"
        ."                windowInsetsController.isAppearanceLightStatusBars = !isSystemDarkMode\n"
        ."                windowInsetsController.isAppearanceLightNavigationBars = !isSystemDarkMode\n"
        ."\n"
        .'                Log.d("StatusBar", "System bars style: auto")'."\n"
        ."            }\n"
        ."        }\n"
        ."    }\n";

    file_put_contents(
        $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt',
        "package com.nativephp.mobile.ui\n\nclass MainActivity {\n".$configure.$bridge."}\n",
    );

    return $root;
}

// Resolved from this file, never base_path(): the mobile-app composer root
// points base_path() at mobile-app/, which has no scripts/ directory.
function systemBarScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_android_system_bar_appearance.php';

    expect(is_file($script))->toBeTrue("The patch script is not at {$script}.");

    return $script;
}

function runSystemBarPatch(string $root): array
{
    $process = proc_open(
        ['php', systemBarScript()],
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

function patchedMainActivity(string $root): string
{
    return (string) file_get_contents(
        $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt',
    );
}

it('gives the page a way to tell the window which way it is painted', function (): void {
    $root = systemBarScaffold();

    expect(runSystemBarPatch($root)['status'])->toBe(0);

    $patched = patchedMainActivity($root);

    expect($patched)->toContain('@android.webkit.JavascriptInterface')
        ->and($patched)->toContain('fun setSystemBarAppearance(dark: Boolean)')
        ->and($patched)->toContain('beatraxWebTheme = dark');
});

// Applying the flags from the bridge alone held on the device until the phone's
// own theme changed: configureStatusBar() then re-derived both from the OS and
// overwrote the page's answer. Verified on a Galaxy S24 Ultra -- the status bar
// was correct at 8.37:1 and went back to 1.09:1 the moment night mode flipped.
it('has the OS branch prefer what the page reported', function (): void {
    $root = systemBarScaffold();
    runSystemBarPatch($root);

    $patched = patchedMainActivity($root);

    expect($patched)->toContain('val beatraxDark = beatraxWebTheme ?: isSystemDarkMode')
        ->and($patched)->toContain('isAppearanceLightStatusBars = !beatraxDark')
        ->and($patched)->toContain('isAppearanceLightNavigationBars = !beatraxDark')
        // The OS answer is what remains on a launch where the page never
        // reports, so the field starts null rather than at either theme.
        ->and($patched)->toContain('private var beatraxWebTheme: Boolean? = null');
});

// A JS bridge method runs on the WebView's own thread and the insets controller
// is main-thread only. Off it the call is dropped with no exception, which
// reads exactly like a bar that ignored the theme.
it('sets the flags on the main thread, where they land', function (): void {
    $root = systemBarScaffold();
    runSystemBarPatch($root);

    expect(patchedMainActivity($root))->toContain('window.decorView.post { configureStatusBar() }');
});

it('keeps the bridge method the activity already registered', function (): void {
    $root = systemBarScaffold();
    runSystemBarPatch($root);

    expect(patchedMainActivity($root))->toContain('fun openDrawer()');
});

// The generated project is recreated on every build and the patches re-run, so
// a second pass must not stack a second copy of the method.
it('adds the method once however often it runs', function (): void {
    $root = systemBarScaffold();
    runSystemBarPatch($root);
    $second = runSystemBarPatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched')
        ->and(substr_count(patchedMainActivity($root), 'fun setSystemBarAppearance'))->toBe(1);
});

// Silently, and exit 0: a patch that abandoned the build over a renamed anchor
// would cost more than the bar polarity it fixes.
it('says so and leaves the file alone when the anchor has moved', function (): void {
    $root = systemBarScaffold(withBridge: false);
    $before = patchedMainActivity($root);

    $result = runSystemBarPatch($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('anchor missing')
        ->and(patchedMainActivity($root))->toBe($before);
});

it('is in the one list every build runs', function (): void {
    $registry = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/nativephp_patch_all.php');

    expect($registry)->toContain("'nativephp_android_system_bar_appearance'");
});

// Absent on every platform but this one, so the page must not assume it: the
// desktop shell installs no bridge at all and the iOS one answers on a channel
// of its own, which the same reporter calls beside this.
it('is called from the page without assuming the bridge exists', function (): void {
    $app = (string) file_get_contents(dirname(__DIR__, 4).'/resources/js/app.js');

    expect($app)->toContain('window.AndroidBridge?.setSystemBarAppearance?.(dark)')
        // Still the painted answer; it is read once now because two shells ask
        // the same question and a second read could drift from the first.
        ->and($app)->toContain('const dark = pageIsPaintedDark();');
});

// The class is not the answer: measured on the device, after an OS night-mode
// change the root carried neither `dark` nor `light` and the page was dark from
// the prefers-color-scheme media query alone, so a class read reported light
// while the reader looked at a dark screen -- and the bars kept dark glyphs on
// a dark bar at 1.09:1.
it('reads the theme off the colour the page paints, not off a class', function (): void {
    $app = (string) file_get_contents(dirname(__DIR__, 4).'/resources/js/app.js');

    expect($app)->toContain('function pageIsPaintedDark()')
        ->and($app)->toContain('getComputedStyle(el).backgroundColor')
        ->and($app)->not->toContain("setSystemBarAppearance?.(document.documentElement.classList.contains('dark'))");
});
