<?php

declare(strict_types=1);

// A WebResourceRequest has never carried a body, so the generated shell
// captures one in the page and trades it back by request id. It installed that
// capture from onPageFinished — the window load event — and a Livewire
// component committing from Alpine's init() posts before it.
//
// Measured on a Galaxy A51 with a document-start recorder, on /imports/new:
// DOMContentLoaded at +443ms, window.load at +659ms, the POST at +679ms, and
// the capture installed at +688ms. Nine milliseconds. The body was dropped,
// PHP read a zero-byte php://input, Livewire found no components and answered
// 404, and its client reloaded the page — which mounted the component, which
// posted again. 856 navigations deep before anyone looked.

/** @return array<string, string> the anchors the patch rewrites, keyed by what they are */
function postBodyAnchors(): array
{
    return [
        'import' => 'import com.nativephp.mobile.security.LaravelSecurity',
        'companion' => '        var shared: WebViewManager? = null',
        'setup call' => '        setupJavaScriptInterfaces()',
        'interfaces' => '    private fun setupJavaScriptInterfaces() {',
        'capture' => <<<'KOTLIN'
            // Guard against re-injection on every onPageFinished. Without this,
            // each injection wraps XHR.send / window.fetch again.
            if (window.__nphpPostPatched) {
                return "POST+PATCH+PUT interception already installed";
            }
            window.__nphpPostPatched = true;

            var _nphpReqId = 0;

            XMLHttpRequest.prototype.send = function(data) {
                originalXHRSetHeader.call(this, 'X-NativePHP-Req-Id', reqId);
                return originalXHRSend.apply(this, arguments);
            };

            window.fetch = function(url, options) {
                options.headers['X-NativePHP-Req-Id'] = reqId;
                return originalFetch.apply(this, arguments);
            };

KOTLIN,
        'csrf' => '            // Find CSRF token',
        'evaluate' => <<<'KOTLIN'
        view.evaluateJavascript(jsCode) { result ->
            Log.d(TAG, "JavaScript injection result: $result")
        }
KOTLIN,
        'post data' => "                        } else null\n                        // Jump webview-forward session: hand the request",
    ];
}

function postBodyManager(string $without = ''): string
{
    $blocks = postBodyAnchors();

    if ($without !== '') {
        $blocks[$without] = '// this site was rewritten upstream';
    }

    return "package com.nativephp.mobile.network\n\n"
        .$blocks['import']."\n\n"
        ."class WebViewManager {\n\n"
        ."    companion object {\n"
        .$blocks['companion']."\n"
        ."    }\n\n"
        ."    fun setup() {\n"
        .$blocks['setup call']."\n"
        ."    }\n\n"
        ."    fun shouldInterceptRequest(): WebResourceResponse {\n"
        ."                        val postData = if (isWrite) {\n"
        ."                            phpBridge.consumePostData(reqId)\n"
        .$blocks['post data']."\n"
        ."        return response\n"
        ."    }\n\n"
        ."    private fun injectJavaScript(view: WebView) {\n"
        ."        val jsCode = \"\"\"\n"
        ."        (function() {\n"
        ."            window.Native = Native;\n\n"
        .$blocks['capture']
        .$blocks['csrf']."\n"
        ."            findAndSendCsrfToken();\n"
        ."            observer.observe(document.body, { childList: true });\n"
        ."            return \"POST+PATCH+PUT interception installed\";\n"
        ."        })();\n"
        ."    \"\"\".trimIndent()\n\n"
        .$blocks['evaluate']."\n"
        ."    }\n\n"
        .$blocks['interfaces']."\n"
        ."        webView.addJavascriptInterface(JSBridge(phpBridge, TAG), \"AndroidPOST\")\n"
        ."    }\n}\n";
}

function postBodyScaffold(string $manager): string
{
    $root = sys_get_temp_dir().'/beatrax-post-body-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network', 0700, true);
    file_put_contents(postBodyManagerPath($root), $manager);

    return $root;
}

function postBodyManagerPath(string $root): string
{
    return $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
}

// Resolved from this file, never base_path(): the mobile-app Composer root
// points base_path() at mobile-app/, which has no scripts/ directory.
function postBodyScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_android_post_body_at_document_start.php';

    expect(is_file($script))->toBeTrue(sprintf('The patch script is not at %s.', $script));

    return $script;
}

/** @return array{status: int, stdout: string, stderr: string} */
function runPostBodyPatch(string $root): array
{
    $process = proc_open(
        ['php', postBodyScript()],
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

function patchedPostBodyManager(string $root): string
{
    return (string) file_get_contents(postBodyManagerPath($root));
}

/** The string the shell injects on page finish, without the constant beside it */
function injectedScriptOnly(string $patched): string
{
    $from = strpos($patched, 'val jsCode = """');
    $to = strpos($patched, '""".trimIndent()', $from === false ? 0 : $from);

    expect($from)->not->toBeFalse()
        ->and($to)->not->toBeFalse();

    return substr($patched, (int) $from, (int) $to - (int) $from);
}

it('arms the capture before the first script on the page rather than after the last', function (): void {
    $root = postBodyScaffold(postBodyManager());

    expect(runPostBodyPatch($root)['status'])->toBe(0);

    expect(patchedPostBodyManager($root))
        ->toContain('WebViewCompat.addDocumentStartJavaScript(webView, POST_BODY_CAPTURE_JS, setOf("http://127.0.0.1"))')
        ->toContain('import androidx.webkit.WebViewCompat')
        ->toContain('import androidx.webkit.WebViewFeature')
        ->toContain('        armPostBodyCaptureAtDocumentStart()');
});

// Two copies would drift, and the one that drifts is the fallback, because it
// is the copy nobody exercises on a device that supports arming.
it('keeps a single copy of the capture and installs it twice', function (): void {
    $root = postBodyScaffold(postBodyManager());
    runPostBodyPatch($root);

    $patched = patchedPostBodyManager($root);

    expect(substr_count($patched, 'private const val POST_BODY_CAPTURE_JS'))->toBe(1)
        ->and(substr_count($patched, 'POST_BODY_CAPTURE_JS'))->toBe(3)
        ->and(injectedScriptOnly($patched))->not->toContain('window.fetch =');
});

// The old guard returned out of the whole injected script. Once the capture is
// armed that return fires on every page, and the CSRF scrape below it — which
// has to run per page — would never run again.
it('leaves the CSRF scrape reachable once the capture is already installed', function (): void {
    $root = postBodyScaffold(postBodyManager());
    runPostBodyPatch($root);

    $injected = injectedScriptOnly(patchedPostBodyManager($root));

    expect($injected)
        ->toContain('findAndSendCsrfToken();')
        ->not->toContain('__nphpPostPatched');
});

// A body that could not be recovered used to be dispatched as an ordinary
// empty POST, which reads downstream exactly like a request that never had
// one. That is what made the reload loop silent in every log the app writes.
it('reports a body it could not recover instead of posting an empty one', function (): void {
    $root = postBodyScaffold(postBodyManager());
    runPostBodyPatch($root);

    expect(patchedPostBodyManager($root))
        ->toContain('if (expectsBody && postData.isNullOrEmpty()) {')
        ->toContain('has no recoverable body');
});

// The fallback is for WebViews without DOCUMENT_START_SCRIPT. It must not also
// run where the capture is armed, or the page-finish copy re-wraps fetch and
// the joined request-id header becomes unlookupable.
it('falls back to page-finish injection only where it cannot arm', function (): void {
    $root = postBodyScaffold(postBodyManager());
    runPostBodyPatch($root);

    expect(patchedPostBodyManager($root))
        ->toContain('if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {')
        ->toContain('view.evaluateJavascript(POST_BODY_CAPTURE_JS) { result ->');
});

it('patches once however often the build regenerates the project', function (): void {
    $root = postBodyScaffold(postBodyManager());
    runPostBodyPatch($root);
    $second = runPostBodyPatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched');
});

// An unpatched shell degrades to exactly the defect this exists to fix, so a
// moved anchor has to stop the build rather than skip quietly.
it('fails loudly when an anchor it rewrites has moved', function (string $anchor): void {
    $root = postBodyScaffold(postBodyManager(without: $anchor));

    $result = runPostBodyPatch($root);

    expect($result['status'])->not->toBe(0)
        ->and($result['stderr'])->not->toBeEmpty();
})->with(['capture', 'import', 'companion', 'interfaces', 'evaluate', 'post data']);

/** @return array<string, string> the exact text the patch anchors on, as the shipped client carries it */
function postBodyUpstreamAnchors(): array
{
    return [
        'capture start' => '            // Guard against re-injection on every onPageFinished',
        'capture end' => '            // Find CSRF token',
        'import' => 'import com.nativephp.mobile.security.LaravelSecurity',
        'companion' => "        var shared: WebViewManager? = null\n",
        'setup call' => "        setupJavaScriptInterfaces()\n",
        'interfaces' => '    private fun setupJavaScriptInterfaces() {',
        'evaluate' => "        view.evaluateJavascript(jsCode) { result ->\n            Log.d(TAG, \"JavaScript injection result: \$result\")\n        }",
        'post data' => "                        } else null\n                        // Jump webview-forward session: hand the request",
    ];
}

/** The upstream template the generated project is copied from, where it is installed */
function postBodyUpstreamClient(): ?string
{
    $relative = 'vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';

    foreach ([base_path($relative), base_path('mobile-app/'.$relative)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

// The fixture above is written by hand, so on its own it only proves the patch
// rewrites text this file invented. This is what ties the anchors to the client
// the build actually generates.
it('anchors on text the shipped WebView manager really carries', function (): void {
    $upstream = postBodyUpstreamClient();

    if ($upstream === null) {
        expect(true)->toBeTrue();

        return;
    }

    $wrong = [];

    foreach (postBodyUpstreamAnchors() as $what => $anchor) {
        if (substr_count($upstream, $anchor) !== 1) {
            $wrong[] = $what;
        }
    }

    expect($wrong)->toBe([], 'anchors no longer unique in the upstream manager: '.implode(', ', $wrong));
});
