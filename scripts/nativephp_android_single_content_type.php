<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/**
 * Make the Android shell emit Content-Type once, and the route's own.
 *
 * Every response inside the Android WebView carried the field twice — measured
 * on a Galaxy S24 through XHR, which joins duplicate fields with ", ":
 * `/reports/export` answered `text/html, text/csv; charset=UTF-8`, an ordinary
 * page `text/html, text/html; charset=utf-8`. The same build on iPhone carried
 * one. Content-Type is a singleton field under RFC 9110, and the app sets
 * X-Content-Type-Options: nosniff, so the reader is explicitly told to believe
 * the wrong first value rather than look at the bytes.
 *
 * Two halves, both here. Symfony's ResponseHeaderBag::all() is keyed lowercase,
 * so the bridge echoes `content-type:` and parseResponse's case-SENSITIVE map
 * never answered responseHeaders["Content-Type"] — the mimeType argument fell
 * back to its literal "text/html" on every route. And Chromium's WebResourceResponse
 * reader SetHeaders Content-Type from that argument and then AddHeaders — appends —
 * every entry of responseHeaders, so supplying the field in both places always
 * emitted it twice, whatever the case.
 *
 * The header map therefore becomes case-insensitive, and one helper owns the
 * whole constructor: it reads Content-Type whatever its case, splits it into
 * the mimeType and encoding arguments the WebView API reserves for it, and
 * passes the rest of the headers with Content-Type taken out. Content-Length
 * goes with it — the reader always sets that from the stream it is handed, so
 * the on-disk asset path, which put the file size in the map as well, sent
 * that field twice too. All five construction sites go through the helper: the
 * reported one is the page/download path, the other four duplicated the same way.
 *
 * parseResponse also trimmed the body, which iOS does not: a CSV export lost
 * its trailing newline on Android alone. Same function, same divergence.
 *
 * The error response was rewritten past its Content-Type while this was open.
 * It interpolated the request path and an exception message into an HTML
 * document with no escaping and no CSP, and the asset arm reaches it with a
 * path the page chose. It now answers text/plain with nosniff, so there is no
 * markup for the renderer to execute and nothing to escape.
 *
 * @link ../.docs/features/mobile/android-content-type-is-emitted-once.md
 */
$target = beatraxScaffoldPath('android/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt') ?? '';

if (! is_file($target)) {
    fwrite(STDOUT, "nativephp_android_single_content_type: no Android scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($target);

if (str_contains($source, 'beatraxOneContentType')) {
    fwrite(STDOUT, "nativephp_android_single_content_type: already patched.\n");
    exit(0);
}

$helper = <<<'KOTLIN'
    // Chromium SetHeaders Content-Type from the mimeType/encoding arguments and
    // Content-Length from the stream it is handed, then AddHeaders — appends —
    // every responseHeaders entry, so naming either in the map too emits a
    // singleton field twice. The arguments win: the mimeType is also the type
    // the renderer decides with, and the stream length cannot disagree with the
    // bytes that follow.
    private fun beatraxOneContentType(
        headers: Map<String, String>,
        statusCode: Int,
        reasonPhrase: String,
        fallbackMimeType: String,
        fallbackCharset: String,
        data: java.io.InputStream
    ): WebResourceResponse {
        val contentType = headers.entries
            .firstOrNull { it.key.equals("Content-Type", ignoreCase = true) }
            ?.value
            ?.trim()

        val mimeType = contentType?.substringBefore(';')?.trim()
            ?.takeIf { it.isNotEmpty() } ?: fallbackMimeType

        val charset = contentType?.split(';')?.drop(1)
            ?.map { it.trim() }
            ?.firstOrNull { it.startsWith("charset=", ignoreCase = true) }
            ?.substringAfter('=')?.trim()?.trim('"')
            ?.takeIf { it.isNotEmpty() } ?: fallbackCharset

        val derivedByTheWebView = listOf("Content-Type", "Content-Length")

        return WebResourceResponse(
            mimeType,
            charset,
            statusCode,
            reasonPhrase,
            headers.filterKeys { name -> derivedByTheWebView.none { it.equals(name, ignoreCase = true) } },
            data
        )
    }

    private fun guessMimeType(fileName: String): String {
KOTLIN;

/** @var array<int, array{0: string, 1: string, 2: string}> */
$replacements = [
    [
        'the header map is case-sensitive',
        <<<'KOTLIN'
   fun parseResponse(rawResponse: String): Triple<Map<String, String>, String, Int> {
       val headers = mutableMapOf<String, String>()
KOTLIN,
        <<<'KOTLIN'
   fun parseResponse(rawResponse: String): Triple<Map<String, String>, String, Int> {
       // The bridge echoes ResponseHeaderBag::all(), which is keyed lowercase,
       // and every lookup below asks in Title-Case.
       val headers = java.util.TreeMap<String, String>(String.CASE_INSENSITIVE_ORDER)
KOTLIN,
    ],
    [
        'the body is trimmed',
        '       return Triple(headers, body.trim(), statusCode)',
        // A CSV export is bytes, not display text; iOS forwards components[1]
        // as it stands and the trailing newline survives there.
        '       return Triple(headers, body, statusCode)',
    ],
    [
        'the page and download response',
        <<<'KOTLIN'
        return WebResourceResponse(
            responseHeaders["Content-Type"] ?: "text/html",
            responseHeaders["Charset"] ?: "UTF-8",
            statusCode,
            if (statusCode == 200) "OK" else "Error",
            responseHeaders,
            body.byteInputStream()
        )
KOTLIN,
        <<<'KOTLIN'
        return beatraxOneContentType(
            responseHeaders,
            statusCode,
            if (statusCode == 200) "OK" else "Error",
            "text/html",
            "UTF-8",
            body.byteInputStream()
        )
KOTLIN,
    ],
    [
        'the on-disk asset response',
        <<<'KOTLIN'
                WebResourceResponse(
                    responseHeaders["Content-Type"] ?: "application/octet-stream",
                    "UTF-8",
                    200,
                    "OK",
                    responseHeaders,
                    bufferedStream
                )
KOTLIN,
        <<<'KOTLIN'
                beatraxOneContentType(
                    responseHeaders,
                    200,
                    "OK",
                    "application/octet-stream",
                    "UTF-8",
                    bufferedStream
                )
KOTLIN,
    ],
    [
        'the PHP-served asset response',
        <<<'KOTLIN'
                    WebResourceResponse(
                        responseHeaders["Content-Type"] ?: guessMimeType(cleanPath),
                        responseHeaders["Charset"] ?: "UTF-8",
                        statusCode,
                        "OK",
                        responseHeaders,
                        body.byteInputStream()
                    )
KOTLIN,
        <<<'KOTLIN'
                    beatraxOneContentType(
                        responseHeaders,
                        statusCode,
                        "OK",
                        guessMimeType(cleanPath),
                        "UTF-8",
                        body.byteInputStream()
                    )
KOTLIN,
    ],
    [
        'the Jump dev-server forward',
        <<<'KOTLIN'
                return WebResourceResponse(
                    mime, encoding, status, reason, responseHeaders, ByteArrayInputStream(bytes)
                )
KOTLIN,
        <<<'KOTLIN'
                return beatraxOneContentType(
                    responseHeaders, status, reason, mime, encoding, ByteArrayInputStream(bytes)
                )
KOTLIN,
    ],
    [
        'the error response',
        <<<'KOTLIN'
        return WebResourceResponse(
            "text/html",
            "UTF-8",
            code,
            message,
            mapOf("Content-Type" to "text/html"),
            ByteArrayInputStream("<html><body><h1>$code - $message</h1></body></html>".toByteArray())
        )
KOTLIN,
        <<<'KOTLIN'
        // A diagnostic, not a document: $path comes off the page that asked and
        // ${e.message} off whatever threw, and both were interpolated into HTML
        // unescaped. Text with nosniff is markup nothing parses.
        return beatraxOneContentType(
            mapOf("X-Content-Type-Options" to "nosniff"),
            code,
            message,
            "text/plain",
            "UTF-8",
            ByteArrayInputStream("$code $message".toByteArray())
        )
KOTLIN,
    ],
    [
        'the mime-type table',
        '    private fun guessMimeType(fileName: String): String {',
        $helper,
    ],
];

$missing = [];

foreach ($replacements as [$what, $anchor, $patched]) {
    if (! str_contains($source, $anchor)) {
        $missing[] = $what;

        continue;
    }

    $source = str_replace($anchor, $patched, $source);
}

if ($missing !== []) {
    fwrite(STDERR, "nativephp_android_single_content_type: anchor missing in {$target} for:\n  ".implode("\n  ", $missing)."\n");
    fwrite(STDERR, "The generated WebView client changed shape; re-check how it builds a WebResourceResponse before shipping.\n");
    exit(1);
}

if (file_put_contents($target, $source) === false) {
    fwrite(STDERR, "nativephp_android_single_content_type: could not write {$target}.\n");
    exit(1);
}

fwrite(STDOUT, "nativephp_android_single_content_type: the Android shell now sends one Content-Type.\n");
exit(0);
