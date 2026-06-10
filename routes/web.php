<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// App-wide web routes live here. Module-local web routes are loaded from
// each module's ServiceProvider.

/*
 * Service-worker route (D-17/D-19, PWA-03).
 *
 * MUST live in the base routes/web.php — NOT inside a module ServiceProvider —
 * so no module-level auth middleware can wrap it and 302-redirect the SW
 * registration request (Pitfall 2).
 *
 * Headers:
 *   Content-Type: application/javascript — required for SW registration
 *   Cache-Control: no-cache, no-store, must-revalidate — SW must always
 *     fetch the latest version; stale SW → stale assets (T-04-02-03)
 *   Service-Worker-Allowed: / — allows the SW to control the full origin
 *
 * Middleware: ['web'] only — NO auth guard (T-04-02-02)
 */
Route::get('/sw.js', function () {
    return response()
        ->view('sw')
        ->header('Content-Type', 'application/javascript')
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->header('Service-Worker-Allowed', '/');
})->middleware(['web'])->name('sw');

/*
 * Web manifest route (PWA-02).
 *
 * Serves public/site.webmanifest as JSON. In production nginx serves it
 * as a static file; this route exists so the PwaManifestTest can assert
 * 200 + correct body via Laravel's HTTP test client (which routes through
 * PHP, not the real web server). Content-Type is correct for installability.
 *
 * Middleware: ['web'] only — public artifact, no auth guard (T-04-02-02)
 */
Route::get('/site.webmanifest', function () {
    return response(
        file_get_contents(public_path('site.webmanifest')),
        200,
        ['Content-Type' => 'application/manifest+json'],
    );
})->middleware(['web'])->name('site.webmanifest');

/*
 * PWA icon routes (PWA-02).
 *
 * Serves the icon set from public/icons/. Same rationale as the manifest
 * route above — static files for production, routed for test-client
 * coverage. Only the four files generated in Task 1 are served; no
 * wildcard to avoid inadvertent path traversal.
 *
 * Middleware: ['web'] only — public artifacts (T-04-02-02)
 */
Route::get('/icons/{icon}', function (string $icon) {
    $allowed = ['icon-192.png', 'icon-512.png', 'icon-512-maskable.png', 'apple-touch-icon.png'];
    if (! in_array($icon, $allowed, true)) {
        abort(404);
    }
    $path = public_path('icons/'.$icon);
    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->middleware(['web'])->name('pwa.icon');
