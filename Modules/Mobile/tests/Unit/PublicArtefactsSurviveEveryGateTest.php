<?php

declare(strict_types=1);

/*
 * The app mark is embedded in the privacy veil and both lock screens — the
 * surfaces shown precisely when a gate is redirecting everything else. So a
 * gate that catches /icon.png answers an <img> with a page of HTML, and the
 * screen covering a financial app shows a broken image.
 *
 * Driving a real iPhone mid-setup, /icon.png returned 200 with
 * content-type: text/html and the body of "Setting up… · Beatrax".
 * MobileEnsureDatabaseReady already exempted these; MobileEnsureImportCompleted
 * did not, and that is the gate running during setup.
 */

/** @return list<string> the route names every mobile gate must let through */
function publicArtefactRouteNames(): array
{
    return ['site.webmanifest', 'pwa.icon', 'app.icon', 'app.splash'];
}

it('lets the public artefacts through every mobile gate', function (): void {
    $gates = [
        'MobileEnsureDatabaseReady',
        'MobileEnsureImportCompleted',
    ];

    $missing = [];
    foreach ($gates as $gate) {
        $source = (string) file_get_contents(
            base_path('Modules/Mobile/Internal/Http/Middleware/'.$gate.'.php')
        );

        foreach (publicArtefactRouteNames() as $name) {
            if (! str_contains($source, "'".$name."'")) {
                $missing[] = $gate.' does not exempt '.$name;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('still names those routes in the router', function (): void {
    // The exemption is by route name, so a rename would silently un-exempt
    // them — the failure this test exists to catch.
    $routes = (string) file_get_contents(base_path('routes/web.php'));

    foreach (['app.icon', 'app.splash', 'pwa.icon'] as $name) {
        expect($routes)->toContain("name('".$name."')");
    }
});
