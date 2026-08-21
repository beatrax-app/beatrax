<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

// App-wide web routes live here. Module-local web routes are loaded from
// each module's ServiceProvider.

/*
 * Service-worker route.
 *
 * MUST live in the base routes/web.php — NOT inside a module ServiceProvider —
 * so no module-level auth middleware can wrap it and 302-redirect the SW
 * registration request.
 *
 * Headers:
 *   Content-Type: application/javascript — required for SW registration
 *   Cache-Control: no-cache, no-store, must-revalidate — SW must always
 *     fetch the latest version; stale SW → stale assets
 *   Service-Worker-Allowed: / — allows the SW to control the full origin
 *
 * Middleware: ['web'] only — NO auth guard
 */
/*
 * Reads the file into the response body rather than returning a
 * BinaryFileResponse. The mobile bridge forwards a buffered body; a streamed
 * one arrived as the 8-byte PNG signature plus two bytes, under a
 * Content-Length claiming the full 23548 — a header that promised an image and
 * a body that was not one.
 */
if (! function_exists('pngResponse')) {
    function pngResponse(string $path): Response
    {
        if (! is_file($path)) {
            abort(404);
        }

        $bytes = (string) file_get_contents($path);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}

Route::get('/sw.js', function () {
    return response()
        ->view('sw')
        ->header('Content-Type', 'application/javascript')
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->header('Service-Worker-Allowed', '/');
})->middleware(['web'])->name('sw');

/*
 * Web manifest route.
 *
 * Serves public/site.webmanifest as JSON. In production nginx serves it
 * as a static file; this route exists so the PwaManifestTest can assert
 * 200 + correct body via Laravel's HTTP test client (which routes through
 * PHP, not the real web server). Content-Type is correct for installability.
 *
 * Middleware: ['web'] only — public artifact, no auth guard
 */
Route::get('/site.webmanifest', function () {
    return response(
        file_get_contents(public_path('site.webmanifest')),
        200,
        ['Content-Type' => 'application/manifest+json'],
    );
})->middleware(['web'])->name('site.webmanifest');

/*
 * PWA icon routes.
 *
 * Serves the icon set from public/icons/. Same rationale as the manifest
 * route above — static files for production, routed for test-client
 * coverage. Only the four generated icon files are served; no
 * wildcard to avoid inadvertent path traversal.
 *
 * Middleware: ['web'] only — public artifacts
 */
Route::get('/icons/{icon}', function (string $icon) {
    $allowed = ['icon-192.png', 'icon-512.png', 'icon-512-maskable.png', 'apple-touch-icon.png'];
    if (! in_array($icon, $allowed, true)) {
        abort(404);
    }

    return pngResponse(public_path('icons/'.$icon));
})->middleware(['web'])->name('pwa.icon');

/*
 * The app mark and splash, on the same terms as the icon set above.
 *
 * These had no route at all, so on a phone — where there is no web server in
 * front of Laravel and every request is answered by PHP — /icon.png 404'd.
 * The privacy veil and both lock screens embed it, so the screen that covers a
 * financial app when it backgrounds showed a broken image.
 */
Route::get('/icon.png', fn () => pngResponse(public_path('icon.png')))
    ->middleware(['web'])->name('app.icon');

Route::get('/splash.png', fn () => pngResponse(public_path('splash.png')))
    ->middleware(['web'])->name('app.splash');
