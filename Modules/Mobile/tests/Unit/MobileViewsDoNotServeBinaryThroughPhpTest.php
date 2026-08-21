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

it('never embeds a raster image a phone would render', function (): void {
    $offenders = [];

    foreach (mobileRenderedViews() as $view) {
        $body = (string) file_get_contents($view);

        if (preg_match('/src="[^"]*\.(png|jpg|jpeg|gif|ico)"/i', $body) === 1
            || preg_match("/src=\"\\{\\{ asset\\('[^']*\\.(png|jpg|jpeg|gif|ico)'\\) \\}\\}\"/i", $body) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $view);
        }
    }

    expect($offenders)->toBe(
        [],
        'a phone-rendered view embeds a raster image; the iOS bridge truncates binary at the first NUL byte'
    );
});
