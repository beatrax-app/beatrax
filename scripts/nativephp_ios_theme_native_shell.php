<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Hand the iOS shell the app's own theme, for the two things it draws that no
 * stylesheet can reach.
 *
 * The status bar clock and the home indicator. The window is edge-to-edge
 * (`contentInsetAdjustmentBehavior = .never`, `viewport-fit=cover`), so the app
 * paints the pixels behind both, but iOS draws the glyphs on top and picks
 * their polarity from the window's interface style — the PHONE's theme. Android
 * had this wired end to end and iOS had nothing: no UIStatusBarStyle in
 * Info.plist, no preferredColorScheme anywhere in the shell. Whenever the two
 * themes disagreed the clock and the battery sat on the app's own background at
 * roughly 1.05:1, which is invisible on every screen.
 *
 * The WebView's own canvas. WKWebView's default background is opaque white and
 * it shows that white for the whole navigation, from the moment the old
 * document is torn down until the new one paints. On a dark shell every page
 * change flashed white. `nativephp_theme_native_shell.php` fixes exactly this
 * on Android and is Android-only; this is the other half.
 *
 * Both are one script because on iOS they are one file and one signal: the page
 * reports its resolved theme through a WKScriptMessageHandler and the shell
 * uses that answer for the status bar and for the swap colour. Splitting them
 * would leave a tree where one patch applied and the other did not referring to
 * a type that is not there — a build failure in place of a cosmetic bug.
 *
 * The reported payload carries `followsSystem` as well as `dark`, and that flag
 * is the whole reason this is safe. `preferredColorScheme` reaches the status
 * bar by overriding the WINDOW's interface style, and a WKWebView inside an
 * overridden window reports the overridden value to `prefers-color-scheme`. A
 * reader whose theme is `system` resolves their theme from that media query, so
 * pinning it would freeze them at whatever the OS happened to be when the page
 * last spoke — their theme would stop following the phone, which is worse than
 * the bar this fixes. When the reader follows the phone the shell overrides
 * nothing, and the OS answer is already the right one for both surfaces.
 *
 * One knock-on is deliberate and worth naming. While an override is active the
 * body's `@Environment(\.colorScheme)` reads the app's theme rather than the
 * phone's, so the `AppearanceChanged` event the generated body sends from it
 * now announces the app's. Nothing in this product subscribes to it, and the
 * alternative — reading the phone's style from the scene to keep that one event
 * honest — is more machinery than the event is worth.
 *
 * All five anchors are checked before any of them is written: a half-applied
 * patch here does not degrade to the unpatched shell, it fails to compile.
 * A missing anchor is a skip rather than a hard failure, like the other
 * cosmetic patches — the unpatched shell is what shipped until now.
 *
 * The generated iOS project is recreated by `native:install`, so this runs from
 * composer's hooks like the rest.
 */

$target = beatraxScaffoldPath('ios/NativePHP/ContentView.swift') ?? '';

if (! is_file($target)) {
    fwrite(STDOUT, "nativephp_ios_theme_native_shell: no iOS scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($target);

if (str_contains($source, 'BeatraxShellAppearance')) {
    fwrite(STDOUT, "nativephp_ios_theme_native_shell: already patched.\n");
    exit(0);
}

// The observed property goes beside the environment read it replaces as the
// answer for the bars, and the modifier goes on the same body that read it.
const IOS_SHELL_ENVIRONMENT_ANCHOR = "    @Environment(\\.colorScheme) private var colorScheme\n";

// The head of the comment block the generated body ends with, rather than the
// modifier under it: inserting between the two would leave that comment
// explaining a modifier it was not written for.
const IOS_SHELL_BODY_ANCHOR = "        // Push a native AppearanceChanged event to PHP when the system theme\n";

// The last line of WebView.makeUIView's own configuration of the scroll view,
// which is where the WebView exists and is not yet loading anything.
const IOS_SHELL_WEBVIEW_ANCHOR = "        // Configure scrollView for proper safe area handling with viewport-fit=cover\n";

// addNativeHelper() runs for every WebView and outside the DEBUG block the
// console handler lives in, so the channel exists in a shipped build too.
const IOS_SHELL_HANDLER_ANCHOR = "        let contentController = webView.configuration.userContentController\n";

// File scope, beside the other message handler the shell declares there.
const IOS_SHELL_TYPES_ANCHOR = "class ConsoleLogger: NSObject, WKScriptMessageHandler {\n";

$anchors = [
    'ContentView colour-scheme property' => IOS_SHELL_ENVIRONMENT_ANCHOR,
    'ContentView body' => IOS_SHELL_BODY_ANCHOR,
    'WebView scroll-view configuration' => IOS_SHELL_WEBVIEW_ANCHOR,
    'user content controller' => IOS_SHELL_HANDLER_ANCHOR,
    'ConsoleLogger declaration' => IOS_SHELL_TYPES_ANCHOR,
];

$missing = [];

foreach ($anchors as $name => $anchor) {
    if (! str_contains($source, $anchor)) {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    fwrite(STDOUT, 'nativephp_ios_theme_native_shell: anchor missing ('
        .implode(', ', $missing)."); skipping.\n");
    exit(0);
}

$property = <<<'SWIFT'
    @Environment(\.colorScheme) private var colorScheme

    // What the PAGE resolved to, which is the answer the status bar wants and
    // the environment above cannot give: that one is the phone's.
    @ObservedObject private var beatraxShellAppearance = BeatraxShellAppearance.shared
SWIFT;

$bodyModifier = <<<'SWIFT'
        // iOS draws the status bar clock and the home indicator over pixels the
        // app paints, and picks their polarity from the window's interface
        // style. Nil while the reader follows the phone, which is what keeps
        // the WebView's own prefers-color-scheme free to follow it too.
        .preferredColorScheme(beatraxShellAppearance.preferred)

SWIFT;

$webViewPaint = <<<'SWIFT'
        // From the OS until the page reports, because at this point it has not
        // loaded yet and the WebView's default white is what a navigation
        // started before then would flash.
        BeatraxShellAppearance.shared.paintShell(webView)


SWIFT;

$handlerRegistration = <<<'SWIFT'

        // Registered for every WebView and outside DEBUG: without this channel
        // the shell has no way to learn the app's theme, and both the bars and
        // the navigation flash follow the phone's instead.
        contentController.add(BeatraxShellAppearanceBridge(), name: "beatraxShellAppearance")
SWIFT;

$types = <<<'SWIFT'
/// The theme the PAGE resolved to, once it has said so — see
/// scripts/nativephp_ios_theme_native_shell.php for why the shell needs it.
final class BeatraxShellAppearance: ObservableObject {
    static let shared = BeatraxShellAppearance()

    // --color-bg in resources/css/app.css (white / slate-950), so the surfaces
    // the stylesheet cannot reach carry the colour the page is about to paint.
    private static let lightShell = UIColor(red: 1.0, green: 1.0, blue: 1.0, alpha: 1.0)
    private static let darkShell = UIColor(red: 2.0 / 255.0, green: 6.0 / 255.0, blue: 23.0 / 255.0, alpha: 1.0)

    /// Nil while the reader's theme is the phone's own. Overriding then buys
    /// nothing — the OS answer is already right — and costs the reader their
    /// setting: a WKWebView inside an overridden window reports the override
    /// to prefers-color-scheme, which is where `system` reads its answer.
    @Published private(set) var preferred: ColorScheme?

    private var reportedDark: Bool?

    private init() {}

    func report(dark: Bool, followsSystem: Bool) {
        reportedDark = dark
        preferred = followsSystem ? nil : (dark ? .dark : .light)
        paintShell(SharedWebView.shared.webView)
    }

    /// WKWebView paints its own opaque white from the moment a document is torn
    /// down until the next one paints, and no stylesheet runs in that window.
    func paintShell(_ webView: WKWebView?) {
        guard let webView else {
            return
        }

        let dark = reportedDark ?? (UITraitCollection.current.userInterfaceStyle == .dark)
        let shell = dark ? BeatraxShellAppearance.darkShell : BeatraxShellAppearance.lightShell

        webView.isOpaque = false
        webView.backgroundColor = shell
        webView.scrollView.backgroundColor = shell
        webView.underPageBackgroundColor = shell
    }
}

/// The page's answer arriving on the channel addNativeHelper registers as
/// window.webkit.messageHandlers.beatraxShellAppearance.
final class BeatraxShellAppearanceBridge: NSObject, WKScriptMessageHandler {
    func userContentController(
        _ userContentController: WKUserContentController,
        didReceive message: WKScriptMessage
    ) {
        guard let body = message.body as? [String: Any],
              let dark = body["dark"] as? Bool else {
            return
        }

        // Absent means follow the phone, which is the branch that overrides
        // nothing: a payload this shell does not understand must not pin a
        // reader's theme.
        let followsSystem = body["followsSystem"] as? Bool ?? true

        DispatchQueue.main.async {
            BeatraxShellAppearance.shared.report(dark: dark, followsSystem: followsSystem)
        }
    }
}


SWIFT;

$patched = str_replace(IOS_SHELL_ENVIRONMENT_ANCHOR, $property."\n", $source);
$patched = str_replace(IOS_SHELL_BODY_ANCHOR, $bodyModifier.IOS_SHELL_BODY_ANCHOR, $patched);
$patched = str_replace(IOS_SHELL_WEBVIEW_ANCHOR, $webViewPaint.IOS_SHELL_WEBVIEW_ANCHOR, $patched);
$patched = str_replace(IOS_SHELL_HANDLER_ANCHOR, IOS_SHELL_HANDLER_ANCHOR.$handlerRegistration."\n", $patched);
$patched = str_replace(IOS_SHELL_TYPES_ANCHOR, $types.IOS_SHELL_TYPES_ANCHOR, $patched);

if (file_put_contents($target, $patched) === false) {
    fwrite(STDERR, "nativephp_ios_theme_native_shell: could not write {$target}.\n");
    exit(1);
}

fwrite(STDOUT, "nativephp_ios_theme_native_shell: status bar, home indicator and swap colour follow the app.\n");

exit(0);
