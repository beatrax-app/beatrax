<?php

declare(strict_types=1);

/*
 * Paints the two surfaces CSS can never reach: the WebView's own canvas and
 * the Activity window behind it.
 *
 * A WebView's default background is opaque white, and it shows that white for
 * the whole navigation — from the moment the old document is torn down until
 * the new one paints. On a dark device every page change therefore flashed
 * white, and because the window behind it was also unset, the same white
 * showed as bands down the sides while the document laid out. No stylesheet
 * runs during that window, so this has to be set natively.
 *
 * The generated Android project is recreated on every build, so this runs
 * from composer's post-install/post-update hooks like the other patches.
 */

$root = dirname(__DIR__).'/mobile-app/nativephp/android/app/src/main';

$webViewManager = $root.'/java/com/nativephp/mobile/network/WebViewManager.kt';
$themes = $root.'/res/values/themes.xml';
$nightThemes = $root.'/res/values-night/themes.xml';
$colors = $root.'/res/values/colors.xml';
$nightColors = $root.'/res/values-night/colors.xml';

// Matches --color-bg in the app stylesheet (slate-950 / white), so the native
// surfaces and the first painted frame are the same colour.
const SHELL_DARK = '#FF020617';
const SHELL_LIGHT = '#FFFFFFFF';

function patchWebViewBackground(string $file): void
{
    if (! is_file($file)) {
        echo "nativephp_theme_native_shell: WebViewManager.kt not found; skipping.\n";

        return;
    }

    $source = (string) file_get_contents($file);

    if (str_contains($source, 'beatraxShellBackground')) {
        echo "nativephp_theme_native_shell: WebView background already patched.\n";

        return;
    }

    $anchor = '        WebView.setWebContentsDebuggingEnabled(true)';

    if (! str_contains($source, $anchor)) {
        echo "nativephp_theme_native_shell: WebView settings anchor missing; skipping.\n";

        return;
    }

    $patch = <<<'KOTLIN'
        // Android auto-inverts web content whenever the host theme is dark and
        // the page has not declared a color-scheme. It inverted an already-dark
        // page, engaging and disengaging across repaints — a theme flicker with
        // no DOM change behind it. The page owns its own dark styling.
        if (androidx.webkit.WebViewFeature.isFeatureSupported(
                androidx.webkit.WebViewFeature.ALGORITHMIC_DARKENING)) {
            androidx.webkit.WebSettingsCompat.setAlgorithmicDarkeningAllowed(webView.settings, false)
        }

        // The WebView paints THIS colour while a document is being swapped,
        // instead of its default white. Read from the OS night-mode flag so
        // it matches the shell the page itself is about to paint.
        val nightMode = (context.resources.configuration.uiMode and
            android.content.res.Configuration.UI_MODE_NIGHT_MASK) ==
            android.content.res.Configuration.UI_MODE_NIGHT_YES
        val beatraxShellBackground = if (nightMode) 0xFF020617.toInt() else 0xFFFFFFFF.toInt()
        webView.setBackgroundColor(beatraxShellBackground)

KOTLIN;

    file_put_contents($file, str_replace($anchor, $patch.$anchor, $source));
    echo "nativephp_theme_native_shell: WebView background painted.\n";
}

function patchThemeBackground(string $file, string $colorName): void
{
    if (! is_file($file)) {
        return;
    }

    $source = (string) file_get_contents($file);

    if (str_contains($source, 'android:windowBackground')) {
        return;
    }

    $anchor = '<item name="android:windowDrawsSystemBarBackgrounds">true</item>';

    if (! str_contains($source, $anchor)) {
        return;
    }

    // The window sits behind the WebView and shows through wherever the
    // document has not laid out yet — the bands down the sides.
    $item = '<item name="android:windowBackground">@color/'.$colorName.'</item>'."\n        ";

    file_put_contents($file, str_replace($anchor, $item.$anchor, $source));
    echo 'nativephp_theme_native_shell: window background set in '.basename(dirname($file))."/themes.xml.\n";
}

function ensureColor(string $file, string $name, string $value): void
{
    $directory = dirname($file);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        return;
    }

    if (! is_file($file)) {
        file_put_contents(
            $file,
            '<?xml version="1.0" encoding="utf-8"?>'."\n".'<resources>'."\n".'</resources>'."\n",
        );
    }

    $source = (string) file_get_contents($file);

    if (str_contains($source, 'name="'.$name.'"')) {
        return;
    }

    $entry = '    <color name="'.$name.'">'.$value.'</color>'."\n".'</resources>';
    file_put_contents($file, str_replace('</resources>', $entry, $source));
}

patchWebViewBackground($webViewManager);

ensureColor($colors, 'beatrax_shell', SHELL_LIGHT);
ensureColor($nightColors, 'beatrax_shell', SHELL_DARK);

patchThemeBackground($themes, 'beatrax_shell');
patchThemeBackground($nightThemes, 'beatrax_shell');
