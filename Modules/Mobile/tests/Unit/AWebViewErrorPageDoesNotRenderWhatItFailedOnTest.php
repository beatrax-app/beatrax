<?php

declare(strict_types=1);

// errorResponse() interpolates the request path and an exception message into
// an HTML document with no escaping and no CSP -- and it is reached from the
// asset path, where the path comes off the page. A WebView error page is a
// diagnostic, not a document: it has no markup worth parsing, so it stops
// being parsed as markup at all.

function webViewErrorScaffold(): ?string
{
    $relative = 'vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt';

    $upstream = null;
    foreach ([base_path($relative), base_path('mobile-app/'.$relative)] as $candidate) {
        if (is_file($candidate)) {
            $upstream = (string) file_get_contents($candidate);
            break;
        }
    }

    if ($upstream === null) {
        return null;
    }

    $root = sys_get_temp_dir().'/beatrax-webview-error-'.bin2hex(random_bytes(6));
    mkdir(dirname(webViewErrorClientPath($root)), 0700, true);
    file_put_contents(webViewErrorClientPath($root), $upstream);

    return $root;
}

function webViewErrorClientPath(string $root): string
{
    return $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt';
}

function patchedWebViewErrorClient(): string
{
    $root = webViewErrorScaffold();

    expect($root)->not->toBeNull('The upstream WebView client is not installed under either Composer root.');

    $process = proc_open(
        ['php', dirname(__DIR__, 4).'/scripts/nativephp_android_single_content_type.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['BEATRAX_NATIVE_ROOT' => (string) $root, 'PATH' => (string) getenv('PATH')],
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, (string) $stdout.(string) $stderr);

    return (string) file_get_contents(webViewErrorClientPath((string) $root));
}

it('builds no markup around the message it was handed', function (): void {
    $patched = patchedWebViewErrorClient();

    expect($patched)
        ->not->toContain('<html><body><h1>$code - $message</h1></body></html>')
        ->not->toContain('<h1>');
});

// "Asset not found: $path" and "Error loading asset: ${e.message}" are the two
// call sites, and $path is whatever the page asked for.
it('serves the diagnostic as text rather than as a document', function (): void {
    $patched = patchedWebViewErrorClient();

    expect($patched)->toContain('ByteArrayInputStream("$code $message".toByteArray())')
        ->toContain("            \"text/plain\",\n            \"UTF-8\",");
});

// text/plain is only a promise while something is stopping the renderer
// second-guessing it; the helper strips Content-Type out of the map, so this
// header is the one thing left in it.
it('tells the renderer not to sniff a type of its own', function (): void {
    $patched = patchedWebViewErrorClient();

    expect($patched)->toContain('mapOf("X-Content-Type-Options" to "nosniff")');
});
