<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Hands the app's own theme to the two surfaces it draws behind but does not
 * own: the status bar and the gesture navigation bar.
 *
 * The window is edge-to-edge (`setDecorFitsSystemWindows(window, false)`), so
 * both bars are transparent and the app paints the pixels underneath them. What
 * the app cannot paint is the CLOCK, the signal glyphs and the nav chevrons —
 * Android draws those, and picks light or dark from the window's appearance
 * flags, which default to the OS night-mode setting.
 *
 * Whenever the reader's app theme disagrees with their phone's, the two answers
 * disagree with them. Measured on a Galaxy S24 Ultra with the app on Light and
 * the OS on dark: white glyphs on the app's own #f8fafc at 1.05:1, an invisible
 * clock on every screen. The converse (app Dark, OS light) is 1.09:1.
 *
 * `theme-color` cannot fix it. It applies to a browser's own chrome, and this
 * is a WebView with no chrome — the bars belong to the window. So the page
 * reports its resolved theme through the JS bridge the activity already
 * installs, and the activity remembers it.
 *
 * Remembers, rather than only applies: `configureStatusBar()` sets the same two
 * flags from `resources.configuration.uiMode`, and runs at startup and again on
 * every night-mode configuration change. Setting them from the bridge alone was
 * verified on the device and held until the phone's own theme changed, at which
 * point the activity overwrote the page's answer with the OS's — a status bar
 * that was right until the moment the reader touched the setting that breaks it.
 * So the reported value is stored and `configureStatusBar()` prefers it.
 *
 * The generated Android project is recreated on every build, so this runs from
 * composer's post-install/post-update hooks like the other patches.
 */

$root = beatraxScaffoldPath('android/app/src/main') ?? '';

$mainActivity = $root.'/java/com/nativephp/mobile/ui/MainActivity.kt';

function patchSystemBarAppearance(string $file): void
{
    if (! is_file($file)) {
        echo "nativephp_android_system_bar_appearance: MainActivity.kt not found; skipping.\n";

        return;
    }

    $source = (string) file_get_contents($file);

    if (str_contains($source, 'setSystemBarAppearance')) {
        echo "nativephp_android_system_bar_appearance: already patched.\n";

        return;
    }

    // The bridge class the activity already registers as window.AndroidBridge,
    // matched on its declaration so a new method above openDrawer cannot move it.
    $anchor = "    inner class AndroidBridge {\n";

    // The auto branch of configureStatusBar(). The else branch computes the same
    // two values from the same expression, so the match runs on through the
    // blank line and the log call only auto has after it -- and stops before
    // the emoji in that log's text, which no anchor should depend on.
    $autoBranch = "                windowInsetsController.isAppearanceLightStatusBars = !isSystemDarkMode\n"
        ."                windowInsetsController.isAppearanceLightNavigationBars = !isSystemDarkMode\n"
        ."\n"
        .'                Log.d("StatusBar",';

    if (! str_contains($source, $anchor) || ! str_contains($source, $autoBranch)) {
        echo "nativephp_android_system_bar_appearance: anchor missing; skipping.\n";

        return;
    }

    $bridge = <<<'KOTLIN'
    /**
     * The theme the PAGE resolved to, once it has said so. Null until then, and
     * on any launch where the page never reports — where the OS remains the
     * only answer available, which is the behaviour without this patch.
     */
    private var beatraxWebTheme: Boolean? = null

    inner class AndroidBridge {
        /**
         * Called from resources/js/app.js on load and on every theme change.
         *
         * Stored as well as applied: configureStatusBar() re-derives these two
         * flags from the OS on every night-mode change, so applying alone lasts
         * only until the reader touches their phone's theme.
         *
         * Posted to the view because a JS bridge method runs on the WebView's
         * own thread and the window controller is main-thread only — off it the
         * call is dropped without an exception, which reads exactly like a bar
         * that ignored the theme.
         */
        @android.webkit.JavascriptInterface
        fun setSystemBarAppearance(dark: Boolean) {
            beatraxWebTheme = dark
            window.decorView.post { configureStatusBar() }
        }


KOTLIN;

    // `isAppearanceLight*` means "the bar is light, so draw DARK glyphs", which
    // is why both take the negation.
    $auto = "                val beatraxDark = beatraxWebTheme ?: isSystemDarkMode\n"
        ."                windowInsetsController.isAppearanceLightStatusBars = !beatraxDark\n"
        ."                windowInsetsController.isAppearanceLightNavigationBars = !beatraxDark\n"
        ."\n"
        .'                Log.d("StatusBar",';

    $patched = str_replace($anchor, $bridge, $source);
    $patched = str_replace($autoBranch, $auto, $patched);

    file_put_contents($file, $patched);
    echo "nativephp_android_system_bar_appearance: system bar appearance bridged.\n";
}

patchSystemBarAppearance($mainActivity);
