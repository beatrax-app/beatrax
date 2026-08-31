<?php

declare(strict_types=1);

// The iOS window is edge-to-edge, so the app paints the pixels behind the
// status bar and the home indicator while iOS draws the clock and the glyphs on
// top of them -- taking their polarity from the window's interface style, which
// is the PHONE's theme. Android had this wired end to end; iOS had no
// UIStatusBarStyle and no preferredColorScheme anywhere in the shell. The same
// file owns the other half: a WKWebView paints its own opaque white for the
// whole navigation, so every page change on a dark shell flashed white.

function iosShellScaffold(bool $withTypesAnchor = true): string
{
    $root = sys_get_temp_dir().'/beatrax-ios-shell-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/ios/NativePHP', 0700, true);

    // Indentation-exact, like the anchors: every one of them is matched at the
    // real column the generated shell writes it at.
    $consoleLogger = $withTypesAnchor
        ? "class ConsoleLogger: NSObject, WKScriptMessageHandler {\n}\n"
        : "class SomethingElse: NSObject {\n}\n";

    $source = <<<'SWIFT'
import SwiftUI
import WebKit

struct ContentView: View {
    @ObservedObject private var nativeUIBridge = NativeUIBridge.shared
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        Group {
        }
        .background(EscapeHatchGesture())
        // Push a native AppearanceChanged event to PHP when the system theme
        // flips (Control Center toggle, sunset auto-switch).
        .onChange(of: colorScheme) { newScheme in
            let mode = newScheme == .dark ? "dark" : "light"
            LaravelBridge.shared.send?("AppearanceChanged", ["mode": mode])
        }
    }
}

struct WebView: UIViewRepresentable {
    func makeUIView(context: Context) -> WKWebView {
        let webView = WKWebView(frame: .zero, configuration: WKWebViewConfiguration())

        addSwipeGestureSupport(webView: webView, context: context)

        // Configure scrollView for proper safe area handling with viewport-fit=cover
        webView.scrollView.contentInsetAdjustmentBehavior = .never

        return webView
    }

    func addNativeHelper(webView: WKWebView) {
        let contentController = webView.configuration.userContentController

        contentController.addUserScript(WKUserScript())
    }
}

SWIFT;

    file_put_contents($root.'/nativephp/ios/NativePHP/ContentView.swift', $source."\n".$consoleLogger);

    return $root;
}

// Resolved from this file, never base_path(): the mobile-app composer root
// points base_path() at mobile-app/, which has no scripts/ directory.
function iosShellScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_ios_theme_native_shell.php';

    expect(is_file($script))->toBeTrue("The patch script is not at {$script}.");

    return $script;
}

function runIosShellPatch(string $root): array
{
    $process = proc_open(
        ['php', iosShellScript()],
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

function patchedIosShell(string $root): string
{
    return (string) file_get_contents($root.'/nativephp/ios/NativePHP/ContentView.swift');
}

it('gives the page a channel to tell the shell which way it is painted', function (): void {
    $root = iosShellScaffold();

    expect(runIosShellPatch($root)['status'])->toBe(0);

    $patched = patchedIosShell($root);

    expect($patched)->toContain('final class BeatraxShellAppearanceBridge: NSObject, WKScriptMessageHandler')
        ->and($patched)->toContain('contentController.add(BeatraxShellAppearanceBridge(), name: "beatraxShellAppearance")');
});

// The status bar and the home indicator are the two surfaces the app draws
// behind and does not own; both take their polarity from the window's style.
it('hands the reported theme to the window the bars are drawn on', function (): void {
    $root = iosShellScaffold();
    runIosShellPatch($root);

    $patched = patchedIosShell($root);

    expect($patched)->toContain('.preferredColorScheme(beatraxShellAppearance.preferred)')
        ->and($patched)->toContain('@ObservedObject private var beatraxShellAppearance = BeatraxShellAppearance.shared');
});

// A WKWebView inside an overridden window reports the override to
// prefers-color-scheme, and that media query is where a reader on `system`
// resolves their theme. Pinning it would stop their theme following the phone,
// which is a worse fault than the bar this exists to fix.
it('overrides nothing while the reader follows the phone', function (): void {
    $root = iosShellScaffold();
    runIosShellPatch($root);

    $patched = patchedIosShell($root);

    expect($patched)->toContain('preferred = followsSystem ? nil : (dark ? .dark : .light)')
        // Absent means follow the phone: a payload the shell cannot read must
        // not be the thing that pins a reader's theme.
        ->and($patched)->toContain('let followsSystem = body["followsSystem"] as? Bool ?? true');
});

// WKWebView's default background is opaque white and it shows it from the
// moment the old document is torn down until the new one paints. No stylesheet
// runs in that window, so it has to be set natively.
it('paints the canvas the WebView shows between documents', function (): void {
    $root = iosShellScaffold();
    runIosShellPatch($root);

    $patched = patchedIosShell($root);

    expect($patched)->toContain('webView.isOpaque = false')
        ->and($patched)->toContain('webView.backgroundColor = shell')
        ->and($patched)->toContain('webView.scrollView.backgroundColor = shell')
        ->and($patched)->toContain('webView.underPageBackgroundColor = shell')
        // slate-950, which is --color-bg in resources/css/app.css.
        ->and($patched)->toContain('red: 2.0 / 255.0, green: 6.0 / 255.0, blue: 23.0 / 255.0');
});

// The WebView is created before the page has loaded, so a navigation started
// before the first report would still flash the default white.
it('paints it from the phone until the page has spoken', function (): void {
    $root = iosShellScaffold();
    runIosShellPatch($root);

    $patched = patchedIosShell($root);

    expect($patched)->toContain('BeatraxShellAppearance.shared.paintShell(webView)')
        ->and($patched)->toContain('reportedDark ?? (UITraitCollection.current.userInterfaceStyle == .dark)');
});

// The generated project is recreated on every build and the patches re-run, so
// a second pass must not stack a second copy of anything.
it('applies once however often it runs', function (): void {
    $root = iosShellScaffold();
    runIosShellPatch($root);
    $second = runIosShellPatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched')
        ->and(substr_count(patchedIosShell($root), 'final class BeatraxShellAppearance:'))->toBe(1)
        ->and(substr_count(patchedIosShell($root), '.preferredColorScheme('))->toBe(1);
});

// All five anchors or none. Unlike the other cosmetic patches this one does not
// degrade to the unpatched shell when it half-applies: a body that names
// BeatraxShellAppearance without the type beside ConsoleLogger does not
// compile, and a build that fails costs more than the bar polarity.
it('writes nothing at all when one anchor of five has moved', function (): void {
    $root = iosShellScaffold(withTypesAnchor: false);
    $before = patchedIosShell($root);

    $result = runIosShellPatch($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('anchor missing')
        ->and(patchedIosShell($root))->toBe($before);
});

it('is in the one list every build runs', function (): void {
    $registry = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/nativephp_patch_all.php');

    expect($registry)->toContain("'nativephp_ios_theme_native_shell'");
});

// Both phones answer the same question, and two reporters would drift: the
// Android bridge was the only caller until the iOS channel existed.
it('is reported by the same page function that reports to Android', function (): void {
    $app = (string) file_get_contents(dirname(__DIR__, 4).'/resources/js/app.js');

    $reporter = substr(
        $app,
        (int) strpos($app, 'function reportThemeToSystemBars()'),
        400,
    );

    expect($reporter)->toContain('window.AndroidBridge?.setSystemBarAppearance?.(dark)')
        ->and($reporter)->toContain('window.webkit?.messageHandlers?.beatraxShellAppearance?.postMessage')
        ->and($reporter)->toContain("dataset.themeChoice ?? 'system') === 'system'");
});
