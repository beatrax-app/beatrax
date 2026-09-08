<?php

declare(strict_types=1);

use Modules\Community\Internal\Shell\UnopenedUrl;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Public\Contracts\ExternalUrlOpener;

// The fallback this replaced was typed by the desktop package: it implemented
// Native\Desktop\Contracts\Shell, which the mobile Composer root does not
// install. Resolving it on a phone was a fatal — measured as a 500 on an
// iPhone — rather than the no-op it was written to be.
it('resolves the opener to the platform-free fallback outside a native runtime', function (): void {
    expect((bool) config('nativephp-internal.running', false))->toBeFalse();

    expect(app(ExternalUrlOpener::class))->toBeInstanceOf(UnopenedUrl::class);
});

// Every implementation outside Modules/Desktop is one a phone can resolve, so
// none of them may name the package only the desktop root installs. The old
// fallback named it and was therefore a fatal on the runtime it existed for.
it('names the desktop package in no implementation a phone can resolve', function (): void {
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS)
    );

    $scanned = 0;
    $offenders = [];

    /** @var SplFileInfo $entry */
    foreach ($walk as $entry) {
        $path = (string) $entry;

        if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $scanned++;
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'implements ExternalUrlOpener')) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $path);

        if (str_starts_with($relative, 'Modules/Desktop/')) {
            continue;
        }

        if (str_contains($source, 'Native\\Desktop')) {
            $offenders[] = $relative;
        }
    }

    expect($scanned)->toBeGreaterThan(500, 'the walk read almost nothing, so an empty result proves nothing');

    expect($offenders)->toBe([], implode("\n", [
        'These implement the opener outside Modules/Desktop, so a phone can resolve them,',
        'and they name a package the mobile Composer root does not install. That is a fatal',
        'on the one runtime the implementation exists to serve. Files:',
        ...$offenders,
    ]));
});

it('does not throw when opening an allow-listed external URL outside the native runtime', function (): void {
    $action = app(OpenExternalUrlAction::class);

    expect($action('https://github.com/beatrax-app/beatrax/releases/latest'))->toBeFalse(
        'nothing here can open a URL, and saying it did is the defect this seam exists to prevent'
    );
});
