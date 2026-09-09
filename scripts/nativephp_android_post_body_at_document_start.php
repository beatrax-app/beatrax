<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Arm the Android POST-body capture before the page can post.
 *
 * A WebResourceRequest carries a method, a URL and headers, and no body —
 * Android has never exposed one. The generated shell works around that by
 * patching `window.fetch` and `XMLHttpRequest.prototype.send` in the page,
 * stashing the body against a generated id and putting that id on the request
 * as `X-NativePHP-Req-Id`, which `shouldInterceptRequest` then trades back for
 * the bytes. It installs that patch from `onPageFinished`.
 *
 * `onPageFinished` is the window `load` event, and a Livewire component that
 * commits from Alpine's `init()` posts before it. Measured on a Galaxy A51
 * (Android 13, WebView 151) with a document-start recorder, on /imports/new:
 *
 *     +443ms  DOMContentLoaded
 *     +659ms  window.load
 *     +679ms  fetch-called  POST /livewire-<hash>/update  shimInstalled=false
 *     +688ms  shim-assigned-fetch
 *
 * Nine milliseconds. The POST left through the original `fetch`, carried no
 * `X-NativePHP-Req-Id`, and `consumePostData` had nothing to return — so the
 * shell dispatched the request with an empty body. PHP then saw a POST with a
 * null CONTENT_LENGTH and a zero-byte `php://input`; Livewire's `handleUpdate`
 * found no `components` in it and called `abort(404)`; Livewire's client
 * treated the 404 as a failed commit and reloaded the page, which mounted the
 * component again, which posted again. A ~1.8s loop that never converged, 856
 * navigations deep when it was found, and silent in every log the app writes.
 *
 * Only a commit issued while the document is still loading loses this race,
 * which is why every ordinary click worked and this looked like one broken
 * screen rather than a platform defect.
 *
 * The fix installs the same capture through `WebViewCompat`'s document-start
 * script, which runs before any script on the page on every navigation. The
 * page-finish injection is kept for WebViews without DOCUMENT_START_SCRIPT,
 * and both share one copy of the JavaScript — this patch moves the block into
 * a constant rather than restating it, so the fallback cannot drift from the
 * armed version.
 *
 * Two things it also repairs, both consequences of the original early return:
 *
 *  - The re-injection guard used to `return` out of the whole injected script.
 *    Once the capture is installed at document start that return fires on
 *    every page, and everything after it — the CSRF token scrape and the
 *    MutationObserver that keeps it current — would never run again. The guard
 *    now brackets only the part it was written to protect.
 *  - A POST whose body could not be recovered was dispatched anyway, as an
 *    ordinary empty POST. That is indistinguishable downstream from a request
 *    that genuinely had no body, and it is what made a dropped body silent.
 *    It is now reported.
 *
 * The generated tree is rebuilt by `native:install`, so this is applied from
 * composer's hooks rather than hand-edited. It is idempotent, and a missing
 * anchor is a hard failure rather than a silent skip, because an unpatched
 * shell degrades to exactly the behaviour this exists to fix.
 *
 * @link ../.docs/features/mobile/architecture.md
 */

$relative = 'android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
$target = beatraxScaffoldPath($relative) ?? '';

if (! is_file($target)) {
    // The native scaffold is generated on demand and is absent from a fresh
    // checkout; there is nothing to patch until `native:install` has run.
    fwrite(STDOUT, "nativephp_android_post_body_at_document_start: no Android scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($target);

if (str_contains($source, 'addDocumentStartJavaScript')) {
    fwrite(STDOUT, "nativephp_android_post_body_at_document_start: already patched.\n");
    exit(0);
}

/**
 * Replaces one occurrence of an anchor, or fails loudly.
 */
function beatraxReplaceOnce(string $subject, string $anchor, string $replacement, string $what): string
{
    if (substr_count($subject, $anchor) !== 1) {
        fwrite(STDERR, sprintf(
            "nativephp_android_post_body_at_document_start: %s anchor matched %d times, expected 1.\n",
            $what,
            substr_count($subject, $anchor),
        ));
        exit(1);
    }

    return str_replace($anchor, $replacement, $subject);
}

// The capture block is lifted out of the injected script verbatim rather than
// restated here, so the armed copy is byte-for-byte what shipped.
$blockStart = '            // Guard against re-injection on every onPageFinished';
$blockEnd = '            // Find CSRF token';

$startAt = strpos($source, $blockStart);
$endAt = strpos($source, $blockEnd);

if ($startAt === false || $endAt === false || $endAt <= $startAt) {
    fwrite(STDERR, "nativephp_android_post_body_at_document_start: could not delimit the capture block.\n");
    exit(1);
}

$block = substr($source, $startAt, $endAt - $startAt);

if (! str_contains($block, 'X-NativePHP-Req-Id') || ! str_contains($block, 'window.fetch')) {
    fwrite(STDERR, "nativephp_android_post_body_at_document_start: the delimited block is not the capture.\n");
    exit(1);
}

// Standalone, the block needs a function to hold its `return`s, and it must
// not touch document.body — nothing in it does, which is why it can run at
// document start while the CSRF observer below it cannot.
$captureJs = "(function () {\n".rtrim($block)."\n\n            return \"POST+PATCH+PUT interception installed\";\n        })();";

$source = substr_replace($source, '', $startAt, $endAt - $startAt);

$source = beatraxReplaceOnce(
    $source,
    'import com.nativephp.mobile.security.LaravelSecurity',
    "import androidx.webkit.WebViewCompat\nimport androidx.webkit.WebViewFeature\nimport com.nativephp.mobile.security.LaravelSecurity",
    'import',
);

$source = beatraxReplaceOnce(
    $source,
    "        setupJavaScriptInterfaces()\n",
    "        setupJavaScriptInterfaces()\n        armPostBodyCaptureAtDocumentStart()\n",
    'setup() call',
);

$source = beatraxReplaceOnce(
    $source,
    "        var shared: WebViewManager? = null\n",
    "        var shared: WebViewManager? = null\n\n"
    ."        // Kept as one constant because it is installed twice: armed at\n"
    ."        // document start, and evaluated on page finish for WebViews that\n"
    ."        // cannot arm it. Two copies would drift, and the fallback is the\n"
    ."        // copy nobody exercises.\n"
    ."        private const val POST_BODY_CAPTURE_JS = \"\"\"\n".$captureJs."\n\"\"\"\n",
    'companion constant',
);

$source = beatraxReplaceOnce(
    $source,
    '    private fun setupJavaScriptInterfaces() {',
    "    // The page cannot hand over a POST body it has already sent, so the\n"
    ."    // capture has to be in place before the first script on the page runs.\n"
    ."    // Installing it on page finish lost every commit issued from Alpine's\n"
    ."    // init() by single-digit milliseconds.\n"
    ."    private fun armPostBodyCaptureAtDocumentStart() {\n"
    ."        if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {\n"
    ."            Log.w(TAG, \"⚠️ DOCUMENT_START_SCRIPT unsupported — POST bodies are captured on page finish only\")\n"
    ."            return\n"
    ."        }\n\n"
    ."        try {\n"
    ."            WebViewCompat.addDocumentStartJavaScript(webView, POST_BODY_CAPTURE_JS, setOf(\"http://127.0.0.1\"))\n"
    ."            Log.d(TAG, \"✅ POST body capture armed at document start\")\n"
    ."        } catch (e: Exception) {\n"
    ."            Log.e(TAG, \"⚠️ POST body capture could not be armed; load-time commits will lose their body\", e)\n"
    ."        }\n"
    ."    }\n\n"
    .'    private fun setupJavaScriptInterfaces() {',
    'arm function',
);

$source = beatraxReplaceOnce(
    $source,
    "        view.evaluateJavascript(jsCode) { result ->\n            Log.d(TAG, \"JavaScript injection result: \$result\")\n        }",
    "        view.evaluateJavascript(jsCode) { result ->\n            Log.d(TAG, \"JavaScript injection result: \$result\")\n        }\n\n"
    ."        // Where the capture is armed at document start this never runs.\n"
    ."        // Where it cannot be, this is the only thing that installs it,\n"
    ."        // and a load-time commit still loses its body — the guarantee is\n"
    ."        // the arming, not the fallback.\n"
    ."        if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {\n"
    ."            view.evaluateJavascript(POST_BODY_CAPTURE_JS) { result ->\n"
    ."                Log.d(TAG, \"POST body capture (page-finish fallback): \$result\")\n"
    ."            }\n"
    .'        }',
    'page-finish fallback',
);

$source = beatraxReplaceOnce(
    $source,
    "                        } else null\n                        // Jump webview-forward session: hand the request",
    "                        } else null\n\n"
    ."                        // A body that could not be recovered was dispatched\n"
    ."                        // anyway, as an ordinary empty POST — which reads\n"
    ."                        // downstream exactly like a request that never had\n"
    ."                        // one. PHP saw a zero-byte php://input and answered\n"
    ."                        // 404, and nothing on the way there said why.\n"
    ."                        val expectsBody = request.method.equals(\"POST\", ignoreCase = true) ||\n"
    ."                            request.method.equals(\"PUT\", ignoreCase = true) ||\n"
    ."                            request.method.equals(\"PATCH\", ignoreCase = true)\n\n"
    ."                        if (expectsBody && postData.isNullOrEmpty()) {\n"
    ."                            Log.e(TAG, \"❌ \${request.method} \${request.url.encodedPath} has no recoverable body — the page's capture did not run for it\")\n"
    ."                        }\n\n"
    .'                        // Jump webview-forward session: hand the request',
    'unrecoverable body report',
);

file_put_contents($target, $source);

fwrite(STDOUT, 'nativephp_android_post_body_at_document_start: patched '.basename($target).".\n");
