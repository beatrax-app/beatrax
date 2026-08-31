# Android sent every response's Content-Type twice

Measured on a Galaxy S24 and an iPhone side by side, on the same build, through
`XMLHttpRequest.getResponseHeader('content-type')` — which joins duplicate
header fields with `", "`, so two values means the field really arrived twice:

| request | Android | iPhone |
|---|---|---|
| `GET /reports/export` | `text/html, text/csv; charset=UTF-8` | `text/csv; charset=UTF-8` |
| `GET /transactions` | `text/html, text/html; charset=utf-8` | `text/html; charset=utf-8` |

`Content-Type` is a singleton field under RFC 9110 §8.3, and
`Modules/Core/Internal/Http/Middleware/NoStoreFinancialData` sets
`X-Content-Type-Options: nosniff` on every response — so the reader was told to
trust the wrong first value rather than look at the bytes. The extra value was
always `text/html`, always first, whatever the route's real type, and it did not
accumulate across the persistent runtime: one spurious emission per response.

## Two independent halves, both in the generated Android shell

Neither half is enough on its own, which is why the route setting its own
`Content-Type` correctly did not help.

**The header map is case-sensitive and the bridge writes lowercase.** The
Android JNI bridge assembles a raw HTTP message in a PHP snippet it `eval`s
(`php_bridge.c`, `native_persistent_dispatch`), and that snippet echoes
`$response->headers->all()`. `Symfony\Component\HttpFoundation\HeaderBag::all()`
returns `$this->headers`, which `set()` keys through `strtr($key, self::UPPER,
self::LOWER)` — so the wire carries `content-type:`, lowercase. RFC 9110 §5.1
makes that perfectly legal. `PHPWebViewClient.parseResponse` then stored the
lines in a plain `mutableMapOf<String, String>`, and every reader asked in
Title-Case:

```kotlin
responseHeaders["Content-Type"] ?: "text/html"
```

That lookup never answered, on any route. The literal `"text/html"` — the bare
type with no charset, exactly what the device reported first — became the
`mimeType` argument for the CSV export as readily as for a page.

**The WebView takes Content-Type through the constructor, not the map.**
Chromium's `AndroidStreamReaderURLLoader::HeadersComplete` builds the response
head like this:

```cpp
head.headers->SetHeader(net::HttpRequestHeaders::kContentLength, ...);
head.headers->SetHeader(net::HttpRequestHeaders::kContentType, mime_type);
head.mime_type = mime_type;
...
response_delegate_->AppendResponseHeaders(env, head.headers.get());
```

`mime_type` is the `WebResourceResponse` constructor's first argument, and the
charset argument goes to `head.charset` rather than into the field. Then
`AppendResponseHeaders` reaches `WebResourceResponse::GetResponseHeaders`, which
does `headers->AddHeader(name, value)` for every entry of the map — **append,
not replace**. So supplying `Content-Type` in the constructor *and* in the map
always emitted it twice, however the case fell. `Content-Length` had the same
shape: the loader always sets it from the length of the stream it is handed, and
the on-disk asset path put the file size in the map as well.

The status code passed to `HeadersComplete` is a hard-coded `HTTP_OK` on this
path — the `WebResourceResponse`'s own status replaces the status line
afterwards, inside `AppendResponseHeaders` — so both `SetHeader` calls run for
every response the shell serves, not just the 200s.

## Why iOS was already right

`PHPSchemeHandler.forwardToPHP` parses the same raw message and lowercases each
name as it goes ("Store with lowercase key for case-insensitive lookup"), then
hands the whole dictionary to `HTTPURLResponse(headerFields:)`. There is no
second channel for the type — one dictionary, one field, the app's own value.
iOS is the reference here and is deliberately untouched.

## The fix

`scripts/nativephp_android_single_content_type.php`, applied to the generated
project by `nativephp_patch_all.php` and re-applied before every build by
`Modules\Mobile\Internal\Boot\NativeBuildPatches`.

- `parseResponse` builds a `TreeMap(String.CASE_INSENSITIVE_ORDER)`, so
  `Content-Type`, `Location` and `X-PHP-Timing` resolve whatever case the bridge
  wrote. The redirect reader had worked around the same thing by hand —
  `responseHeaders["Location"] ?: responseHeaders["location"]` — and is left as
  it stands, now redundant rather than load-bearing.
- One private helper, `beatraxOneContentType`, owns every `WebResourceResponse`
  construction in the file. It reads `Content-Type` case-insensitively, splits it
  into the `mimeType` and `encoding` arguments the WebView API reserves for it,
  and passes the remaining headers with `Content-Type` and `Content-Length`
  removed. All five construction sites go through it — the page/download path,
  the two asset paths, the Jump dev-server forward and the error response,
  which duplicated the field the same way.
- `parseResponse` no longer returns `body.trim()`. iOS forwards `components[1]`
  as it stands, so an exported CSV kept its trailing newline there and lost it
  on Android alone.
- `errorResponse` answers `text/plain` with `X-Content-Type-Options: nosniff`
  and a bare `"$code $message"` body. That line used to interpolate `$path` —
  off the page that asked for the asset — and `${e.message}` into
  `<html><body><h1>…</h1></body></html>` unescaped, inside a WebView with no
  CSP. An error page has no markup worth parsing, so escaping the two values was
  the smaller fix: taking the document away leaves nothing to escape, and
  `nosniff` stops the renderer choosing a type of its own. This is the one site
  whose header map is not the route's, so the header is written there directly;
  the helper strips only `Content-Type` and `Content-Length`.

The emitted field is therefore the bare type — `text/csv`, not
`text/csv; charset=UTF-8`. The charset is not dropped: it travels as
`head.charset`, which is what Blink decodes the body with, and it is the channel
the API defines for it. Sending the full value as `mimeType` instead would put
`text/csv; charset=UTF-8` into `head.mime_type`, which the renderer compares
against bare types.

## Where a second Content-Type is legitimate

- **Inside a multipart body.** Each part of a `multipart/form-data` or
  `multipart/mixed` body carries its own `Content-Type` part header. Those are
  part headers, not message header fields, and the message still has exactly one
  `Content-Type` naming the multipart type and its boundary. The upload path
  described under [A file cannot cross as multipart, so it crosses as
  base64](architecture.md#a-file-cannot-cross-as-multipart-so-it-crosses-as-base64)
  is the request side of this.
- **A request, not a response.** The duplication rule here is about what the
  shell serves. A request's own `Content-Type` is unrelated and is set by the
  caller.
- **Two identical values.** RFC 9110 §8.6 lets a recipient collapse repeated
  `Content-Length` fields that carry the same decimal value rather than reject
  the message. That is tolerance, not permission — the asset path relied on it
  before this patch, and it still emitted a malformed message.

## What this does not change

The PHP side keeps writing lowercase names. That is what
`ResponseHeaderBag::all()` returns, it is legal, and iOS depends on nothing
else — so the fix stays inside the Android shell rather than reaching into the
bridge's `eval`ed snippet, which is a C string literal compiled into both
platforms' binaries.
