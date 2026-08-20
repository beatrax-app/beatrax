<?php

declare(strict_types=1);

/*
 * The iOS bridge carries a response body as a NUL-terminated C string, so a
 * PHP route cannot serve binary on that platform at all. /icon.png arrived as
 * 10 bytes of a 91552-byte file — truncated exactly where the PNG signature
 * ends and IHDR's length field begins with 00 00 00 0D.
 *
 * The mark is embedded in the privacy veil, both lock screens, the welcome
 * screen and the setup progress screen: every surface a phone shows before or
 * instead of the app, so the broken image was the whole picture. The brand SVG
 * is text, survives the bridge intact, and is already proven on device.
 */

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
