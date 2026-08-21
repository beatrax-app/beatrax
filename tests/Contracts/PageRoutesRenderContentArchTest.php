<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-page-that-renders-the-shell-and-nothing-else
 */

/** @return list<string> */
function mountedComponents(string $uri): array
{
    $html = (string) test()->get($uri)->getContent();

    preg_match_all('/&quot;name&quot;:&quot;([a-z0-9._\-]+)&quot;/', $html, $matches);

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

    preg_match('#<title>(.*?)</title>#s', $html, $m);

    expect(trim($m[1] ?? ''))->not->toBe('Beatrax');
})->with(['/settings/aliases', '/settings/open-banking']);

// The shape that broke it: layouts.app yields, so a page that names it in the
// attribute renders into a slot the layout does not have.
it('never points the Layout attribute at the yield-based app layout', function (): void {
    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (! str_contains($file->getPathname(), '/Http/Livewire/')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, "#[Layout('layouts.app')]")) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], sprintf(
        "layouts.app is a @yield layout — extend it at render time instead:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
