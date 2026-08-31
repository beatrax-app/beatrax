<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;

// Measured on a Galaxy S24 against the same build on an iPhone: every response
// inside the Android shell carried Content-Type twice and the iPhone carried it
// once. `/reports/export` answered `text/html, text/csv; charset=UTF-8`.
//
// Both halves live in the generated WebView client. Its header map is
// case-sensitive while the bridge echoes ResponseHeaderBag::all(), which is
// keyed lowercase, so responseHeaders["Content-Type"] never answered and the
// mimeType argument fell back to its literal "text/html". And Chromium's reader
// SetHeaders Content-Type from that argument before it AddHeaders — appends —
// every entry of responseHeaders, so naming the field in both places emits it
// twice however the case falls.

/** @return array<string, string> the anchor blocks the patch rewrites, keyed by what they are */
function oneContentTypeAnchors(): array
{
    return [
        'parse' => <<<'KOTLIN'
   fun parseResponse(rawResponse: String): Triple<Map<String, String>, String, Int> {
       val headers = mutableMapOf<String, String>()
KOTLIN,
        'body' => '       return Triple(headers, body.trim(), statusCode)',
        'page' => <<<'KOTLIN'
        return WebResourceResponse(
            responseHeaders["Content-Type"] ?: "text/html",
            responseHeaders["Charset"] ?: "UTF-8",
            statusCode,
            if (statusCode == 200) "OK" else "Error",
            responseHeaders,
            body.byteInputStream()
        )
KOTLIN,
        'disk asset' => <<<'KOTLIN'
                WebResourceResponse(
                    responseHeaders["Content-Type"] ?: "application/octet-stream",
                    "UTF-8",
                    200,
                    "OK",
                    responseHeaders,
                    bufferedStream
                )
KOTLIN,
        'php asset' => <<<'KOTLIN'
                    WebResourceResponse(
                        responseHeaders["Content-Type"] ?: guessMimeType(cleanPath),
                        responseHeaders["Charset"] ?: "UTF-8",
                        statusCode,
                        "OK",
                        responseHeaders,
                        body.byteInputStream()
                    )
KOTLIN,
        'jump forward' => <<<'KOTLIN'
                return WebResourceResponse(
                    mime, encoding, status, reason, responseHeaders, ByteArrayInputStream(bytes)
                )
KOTLIN,
        'error' => <<<'KOTLIN'
        return WebResourceResponse(
            "text/html",
            "UTF-8",
            code,
            message,
            mapOf("Content-Type" to "text/html"),
            ByteArrayInputStream("<html><body><h1>$code - $message</h1></body></html>".toByteArray())
        )
KOTLIN,
        'mime table' => '    private fun guessMimeType(fileName: String): String {',
    ];
}

// Every anchor in one file, in the order and at the indentation the generated
// client carries them. The upstream template is unreachable from the desktop
// Composer root — nativephp/mobile is only installed under mobile-app/ — and
// the drift guard below is what keeps this honest where it IS reachable.
function oneContentTypeClient(string $without = ''): string
{
    $blocks = oneContentTypeAnchors();

    if ($without !== '') {
        $blocks[$without] = '// this site was rewritten upstream';
    }

    return "package com.nativephp.mobile.network\n\nclass PHPWebViewClient {\n"
        ."    fun handleAssetRequest(url: String): WebResourceResponse {\n"
        .$blocks['disk asset']."\n"
        .$blocks['php asset']."\n"
        ."    }\n\n"
        ."    fun handlePHPRequest(): WebResourceResponse {\n"
        .$blocks['page']."\n"
        ."    }\n\n"
        .$blocks['parse']."\n"
        .$blocks['body']."\n"
        ."   }\n\n"
        ."    fun forwardToRemote(): WebResourceResponse {\n"
        .$blocks['jump forward']."\n"
        ."    }\n\n"
        ."    private fun errorResponse(code: Int, message: String): WebResourceResponse {\n"
        .$blocks['error']."\n"
        ."    }\n\n"
        .$blocks['mime table']."\n"
        ."        return \"application/octet-stream\"\n"
        ."    }\n}\n";
}

function oneContentTypeScaffold(string $client): string
{
    $root = sys_get_temp_dir().'/beatrax-content-type-'.bin2hex(random_bytes(6));
    mkdir($root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network', 0700, true);
    file_put_contents(oneContentTypeClientPath($root), $client);

    return $root;
}

function oneContentTypeClientPath(string $root): string
{
    return $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt';
}

// Resolved from this file, never base_path(): the mobile-app Composer root
// points base_path() at mobile-app/, which has no scripts/ directory.
function oneContentTypeScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_android_single_content_type.php';

    expect(is_file($script))->toBeTrue("The patch script is not at {$script}.");

    return $script;
}

/** @return array{status: int, stdout: string, stderr: string} */
function runOneContentTypePatch(string $root): array
{
    $process = proc_open(
        ['php', oneContentTypeScript()],
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

function patchedWebViewClient(string $root): string
{
    return (string) file_get_contents(oneContentTypeClientPath($root));
}

/** The upstream template the generated project is copied from, where it is installed */
function oneContentTypeUpstreamClient(): ?string
{
    $relative = 'vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt';

    foreach ([base_path($relative), base_path('mobile-app/'.$relative)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

it('reads the header the bridge actually wrote, whatever case it wrote it in', function (): void {
    $root = oneContentTypeScaffold(oneContentTypeClient());

    expect(runOneContentTypePatch($root)['status'])->toBe(0);

    $patched = patchedWebViewClient($root);

    expect($patched)
        ->toContain('java.util.TreeMap<String, String>(String.CASE_INSENSITIVE_ORDER)')
        ->toContain('it.key.equals("Content-Type", ignoreCase = true)')
        // The literal that reached the device on every route, CSV included.
        ->not->toContain('responseHeaders["Content-Type"] ?: "text/html"');
});

it('names Content-Type in one place per response, not two', function (): void {
    $root = oneContentTypeScaffold(oneContentTypeClient());
    runOneContentTypePatch($root);

    $patched = patchedWebViewClient($root);

    // One construction left in the file: the helper's. Every site reaches the
    // WebView through it, and it takes Content-Type out of the header map
    // because the mimeType and encoding arguments already carry it.
    expect(substr_count($patched, 'WebResourceResponse('))->toBe(1)
        ->and($patched)->toContain('val derivedByTheWebView = listOf("Content-Type", "Content-Length")')
        ->and($patched)->toContain('headers.filterKeys { name -> derivedByTheWebView.none { it.equals(name, ignoreCase = true) } }')
        ->and($patched)->not->toContain('mapOf("Content-Type" to "text/html")');
});

// text/csv; charset=UTF-8 has to reach the WebView as the mime type and the
// encoding separately: Chromium writes the mimeType argument into Content-Type
// verbatim and compares head.mime_type against bare types.
it('splits the route type into the two arguments the WebView reserves for it', function (): void {
    $root = oneContentTypeScaffold(oneContentTypeClient());
    runOneContentTypePatch($root);

    expect(patchedWebViewClient($root))
        ->toContain("contentType?.substringBefore(';')?.trim()")
        ->toContain('it.startsWith("charset=", ignoreCase = true)');
});

// iOS forwards the body as it stands, so the export downloaded there keeps the
// trailing newline the exporter wrote and the Android copy did not.
it('stops trimming a body that is a file rather than a page', function (): void {
    $root = oneContentTypeScaffold(oneContentTypeClient());
    runOneContentTypePatch($root);

    expect(patchedWebViewClient($root))
        ->toContain('return Triple(headers, body, statusCode)')
        ->not->toContain('body.trim()');
});

// The generated project is recreated on every build and the patches re-run.
it('adds the helper once however often it runs', function (): void {
    $root = oneContentTypeScaffold(oneContentTypeClient());
    runOneContentTypePatch($root);
    $second = runOneContentTypePatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched')
        ->and(substr_count(patchedWebViewClient($root), 'private fun beatraxOneContentType'))->toBe(1);
});

// Loudly, and without writing: a half-applied rewrite would leave some routes
// sending the field twice and no sign of which.
it('fails and leaves the file alone when one site has moved', function (): void {
    $root = oneContentTypeScaffold(oneContentTypeClient(without: 'jump forward'));
    $before = patchedWebViewClient($root);

    $result = runOneContentTypePatch($root);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('the Jump dev-server forward')
        ->and(patchedWebViewClient($root))->toBe($before);
});

// The fixture above is hand-built, so it can only stay true to the shell by
// being checked against it. Reachable from the mobile Composer root, which is
// where nativephp/mobile is installed.
it('anchors on text the shipped WebView client really carries', function (): void {
    $upstream = oneContentTypeUpstreamClient();

    if ($upstream === null) {
        expect(true)->toBeTrue();

        return;
    }

    $missing = [];

    foreach (oneContentTypeAnchors() as $what => $anchor) {
        if (! str_contains($upstream, $anchor)) {
            $missing[] = $what;
        }
    }

    expect($missing)->toBe([], 'anchors no longer in the upstream client: '.implode(', ', $missing));
});

it('is in the one list every build runs', function (): void {
    $registry = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/nativephp_patch_all.php');

    $perBuild = (new ReflectionClass(NativeBuildPatches::class))
        ->getReflectionConstant('SCRIPTS')
        ->getValue();

    expect($registry)->toContain("'nativephp_android_single_content_type'")
        ->and($perBuild)->toContain('nativephp_android_single_content_type.php');
});
