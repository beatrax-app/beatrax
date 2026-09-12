<?php

declare(strict_types=1);

/** @return list<string> every view a phone can render */
function mobileRenderedViews(): array
{
    $roots = [
        base_path('resources/views'),
        base_path('Modules/Mobile/Resources/views'),
        base_path('Modules/Auth/Resources/views'),
        base_path('Modules/Core/Resources/views'),
    ];

    $views = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with((string) $file->getFilename(), '.blade.php')) {
                $views[] = (string) $file->getPathname();
            }
        }
    }

    return $views;
}

// The iOS bridge carries a response body as a NUL-terminated C string, so a PHP
// route cannot serve binary on that platform at all: /icon.png arrived as 10 bytes
// of a 91552-byte file, truncated where the PNG signature ends. The brand SVG is
// text, survives the bridge intact, and is already proven on device.

const MOBILE_RASTER_EXTENSIONS = 'png|jpg|jpeg|gif|ico|webp|avif|bmp|tiff?';

// The whole `src` value, whatever is wrapped around it and whichever quote
// holds it. Two hand-spelled wrappers used to be named one at a time — a bare
// path and `asset()` — which left the one the tree actually writes uncovered:
// the brand mark renders through `Vite::asset()`, this file's own comment
// names that view, and swapping its SVG for a PNG passed. `url(` in a style
// attribute and a single-quoted `src` were invisible for the same reason.
/** @return list<string> every raster file a src refers to, however it is written */
function mobileRasterSourcesIn(string $body): array
{
    $found = [];

    // src="…" / src='…' — the wrapper, if any, sits inside the value, so the
    // extension is reached without naming the wrapper at all.
    if (preg_match_all('/\bsrc\s*=\s*("|\')(.*?)\1/is', $body, $attributes) !== false) {
        foreach ($attributes[2] as $value) {
            if (preg_match('/\.('.MOBILE_RASTER_EXTENSIONS.')\b/i', $value) === 1) {
                $found[] = $value;
            }
        }
    }

    // A CSS background reaches the same bridge by a different door.
    if (preg_match_all('/url\(\s*["\']?([^"\')]+)/i', $body, $urls) !== false) {
        foreach ($urls[1] as $value) {
            if (preg_match('/\.('.MOBILE_RASTER_EXTENSIONS.')\b/i', $value) === 1) {
                $found[] = $value;
            }
        }
    }

    return $found;
}

it('reads a raster source through every wrapper and quote a view uses', function (): void {
    expect(mobileRasterSourcesIn('<img src="/icon.png">'))->toHaveCount(1, 'a bare path')
        ->and(mobileRasterSourcesIn('<img src="{{ asset(\'/icon.png\') }}">'))->toHaveCount(1, 'asset()')
        ->and(mobileRasterSourcesIn('<img src="{{ Vite::asset(\'resources/brand/logo.png\') }}">'))->toHaveCount(1, 'the wrapper this tree actually writes')
        ->and(mobileRasterSourcesIn("<img src='/icon.png'>"))->toHaveCount(1, 'a single-quoted attribute')
        ->and(mobileRasterSourcesIn('<div style="background-image:url(\'/icon.png\')">'))->toHaveCount(1, 'a CSS background')
        ->and(mobileRasterSourcesIn('<img src="{{ Vite::asset(\'resources/brand/logo.svg\') }}">'))->toBe([], 'the brand SVG is text and survives the bridge intact')
        ->and(mobileRasterSourcesIn('<img src="{{ $avatar }}">'))->toBe([], 'a value with no extension names no file this rule can judge');
});

it('never embeds a raster image a phone would render', function (): void {
    $offenders = [];
    $views = mobileRenderedViews();

    foreach ($views as $view) {
        foreach (mobileRasterSourcesIn((string) file_get_contents($view)) as $source) {
            $offenders[] = str_replace(base_path().'/', '', $view).' — '.$source;
        }
    }

    // A walk that opened nothing reports the same clean answer an empty tree
    // does, and this rule's subject is every view a phone can reach.
    expect(count($views))->toBeGreaterThan(
        50,
        'Only '.count($views).' phone-rendered views were opened, so a clean answer says nothing.',
    );

    expect($offenders)->toBe(
        [],
        "a phone-rendered view embeds a raster image; the iOS bridge truncates binary at the first NUL byte:\n  "
        .implode("\n  ", $offenders)
    );
});
