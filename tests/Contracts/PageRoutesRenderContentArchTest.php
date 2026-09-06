<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Public\Support\RenderedMarkup;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-page-that-renders-the-shell-and-nothing-else
 */

/** @return list<string> */
function mountedComponents(string $uri): array
{
    $html = (string) test()->get($uri)->getContent();

    $matches = PatternScan::all('/&quot;name&quot;:&quot;([a-z0-9._\-]+)&quot;/', $html);

    return array_values(array_unique($matches[1]));
}

beforeEach(function (): void {
    $this->pageUser = User::query()->create([
        'username' => 'page-render-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($this->pageUser);
});

it('mounts the page component on a settings route', function (string $uri, string $component): void {
    expect(mountedComponents($uri))->toContain($component);
})->with([
    ['/settings/aliases', 'import.aliases-settings-page'],
    ['/settings/open-banking', 'openbanking.open-banking-settings-page'],
]);

it('gives every page a title of its own, not the bare app name', function (string $uri): void {
    $html = (string) test()->get($uri)->getContent();

    expect(RenderedMarkup::of($html)->firstOrFail('title')->text())->not->toBe('Beatrax');
})->with(['/settings/aliases', '/settings/open-banking']);

// The shape that broke it: layouts.app yields, so a page that names it in the
// attribute renders into a slot the layout does not have.
function pageRouteLayoutAttributeIn(string $source): bool
{
    return str_contains($source, "#[Layout('layouts.app')]");
}

it('never points the Layout attribute at the yield-based app layout', function (): void {
    $offenders = [];
    $walked = 0;

    foreach (['Modules', 'app'] as $root) {
        if (! is_dir(base_path($root))) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains($file->getPathname(), '/Http/Livewire/')) {
                continue;
            }

            $walked++;

            if (pageRouteLayoutAttributeIn((string) file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($walked)->toBeGreaterThan(80, 'The component walk found almost nothing, so a clean answer below is the walk being broken rather than the components being right.');

    expect($offenders)->toBe([], sprintf(
        "layouts.app is a @yield layout — extend it at render time instead:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('reads the attribute that broke it and leaves a component layout alone', function (): void {
    expect(pageRouteLayoutAttributeIn("#[Layout('layouts.app')]\nfinal class Whatever"))->toBeTrue('The reader stopped seeing the attribute this rule exists to forbid.')
        // The near miss: a namespaced component layout is a slot-based layout
        // and is exactly what a page is supposed to name.
        ->and(pageRouteLayoutAttributeIn("#[Layout('core::layouts.app')]\nfinal class Whatever"))->toBeFalse('A component layout is being reported as the yield-based one.');
});
