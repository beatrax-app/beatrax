<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * Two settings pages rendered the shell and nothing else: the page component
 * never mounted, <main> was empty, and the tab said only "Beatrax". One of
 * them is linked from /settings as "Aliassen beheren", so it was a reachable
 * dead end.
 *
 * layouts.app is a @yield('content') layout, reached by extending it at render
 * time — 41 pages do exactly that. AliasesSettingsPage was the only page that
 * ALSO declared #[Layout('layouts.app')], and OpenBankingSettingsPage declared
 * no layout at all.
 *
 * Measuring rendered text does not catch this: Alpine expressions inside
 * attributes survive tag-stripping and read as content. What is actually
 * missing is the component, so that is what these assert.
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
