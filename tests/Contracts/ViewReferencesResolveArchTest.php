<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Modules\Core\Public\Support\PatternScan;

// A composer bound to a view that does not exist never throws — it simply
// never fires. Five of them named the deleted top-nav and sat inert for a
// whole phase, so this reads the name back out of the source and asks the
// finder whether anything answers to it.

/**
 * Every first-party PHP root, not the three a view is usually named from. The
 * rule below says "everywhere a view is named"; read from Modules, app and
 * routes alone it was silent about config, database, lang, scripts, tools,
 * bootstrap and the PHP under resources — 177 files it never opened.
 *
 * @return list<string> absolute paths to the PHP sources that name views
 */
function viewReferencePhpFiles(): array
{
    $files = [];
    foreach ([
        base_path('Modules'),
        base_path('app'),
        base_path('routes'),
        base_path('bootstrap'),
        base_path('config'),
        base_path('database'),
        base_path('lang'),
        base_path('resources'),
        base_path('scripts'),
        base_path('tools'),
    ] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }
            // A test may name a view on purpose to assert what happens when it
            // is missing, so the suite is not held to this. bootstrap/cache is
            // compiled output: it is written by the framework from the roots
            // already walked, and is absent on a fresh checkout, so a name in
            // it is either a second reading of one already read or a stale one.
            if (str_contains($path, '/tests/') || str_contains($path, '/bootstrap/cache/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> absolute paths to every repo-owned Blade template */
function viewReferenceBladeFiles(): array
{
    $files = [];
    foreach ([base_path('Modules'), base_path('resources/views')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.blade.php')) {
                $files[] = $path;
            }
        }
    }
    sort($files);

    return $files;
}

// Each entry is [regex, index of the capture group holding the view name].
// `Route::view()` takes the URI first and the view second; every other shape
// here puts the name first.
/** @return list<array{0: string, 1: int}> */
function viewReferencePatterns(): array
{
    return [
        // The channel that rots silently.
        ['/(?:->|::)\s*(?:composer|creator)\s*\(\s*[\'"]([^\'"]+)[\'"]/', 1],
        // Route::view('/uri', 'view::name').
        ['/Route::view\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', 1],
        // response()->view('name').
        ['/response\s*\(\s*\)\s*->\s*view\s*\(\s*[\'"]([^\'"]+)[\'"]/', 1],
        // The bare view() helper — never $obj->view(), Foo::view() or a
        // declaration of a method called view().
        ['/(?<![\w>:$])view\s*\(\s*[\'"]([^\'"]+)[\'"]/', 1],
    ];
}

/** @return list<array{0: string, 1: int}> */
function viewReferenceBladePatterns(): array
{
    return [
        ['/@(?:include|includeIf|extends|each)\s*\(\s*[\'"]([^\'"]+)[\'"]/', 1],
        ['/@(?:includeWhen|includeUnless)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/', 1],
    ];
}

/**
 * @param  list<array{0: string, 1: int}>  $patterns
 * @return list<array{file: string, line: int, name: string}>
 */
function viewReferencesIn(string $path, array $patterns): array
{
    return viewReferencesInSource(
        (string) file_get_contents($path),
        str_replace(base_path().'/', '', $path),
        $patterns,
    );
}

/**
 * @param  list<array{0: string, 1: int}>  $patterns
 * @return list<array{file: string, line: int, name: string}>
 */
function viewReferencesInSource(string $source, string $label, array $patterns): array
{
    $found = [];

    foreach ($patterns as [$pattern, $group]) {
        $matches = PatternScan::allWithOffsets($pattern, $source);

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[$group] as $match) {
            $found[] = [
                'file' => $label,
                'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
                'name' => $match[0],
            ];
        }
    }

    return $found;
}

/** @return list<array{file: string, line: int, name: string}> */
function viewReferencesEverywhere(): array
{
    $found = [];
    foreach (viewReferencePhpFiles() as $path) {
        $found = array_merge($found, viewReferencesIn($path, viewReferencePatterns()));
    }
    foreach (viewReferenceBladeFiles() as $path) {
        $found = array_merge($found, viewReferencesIn($path, viewReferenceBladePatterns()));
    }

    return $found;
}

it('names a view that exists everywhere a view is named', function (): void {
    $references = viewReferencesEverywhere();

    // 94 references today, 20 from PHP and 74 from Blade. Floored far under: a
    // pattern that stopped matching, or a walk that lost a root, reports the
    // same empty unresolved list a tree with no broken name reports.
    expect(count($references))->toBeGreaterThan(30, 'the scan found almost no view references at all — the patterns are wrong, not the tree.');

    /** @var ViewFactoryContract $factory */
    $factory = $this->app->make(ViewFactoryContract::class);

    $unresolved = [];
    foreach ($references as $reference) {
        // A wildcard binds a composer to a family of views rather than to one
        // file, so there is nothing for the finder to resolve.
        if (str_contains($reference['name'], '*')) {
            continue;
        }
        if ($factory->exists($reference['name'])) {
            continue;
        }
        $unresolved[] = $reference['file'].':'.$reference['line'].' names ['.$reference['name'].']';
    }

    expect($unresolved)->toBe([], "A view name nothing answers to. A composer or creator bound to one never fires and never complains; the render channels fatal on the page instead:\n  ".implode("\n  ", $unresolved));
});

it('finds the composer and creator bindings it exists to check', function (): void {
    $composerBindings = [];
    foreach (viewReferencePhpFiles() as $path) {
        foreach (viewReferencesIn($path, [viewReferencePatterns()[0]]) as $reference) {
            $composerBindings[] = $reference['name'];
        }
    }

    // The scan silently returning nothing would pass the assertion above
    // whatever the codebase did. The channel that rots silently is the whole
    // reason this file exists, so it is the one named here.
    expect(in_array('shell::livewire.app-sidebar', $composerBindings, true))->toBeTrue(
        'No composer binding was found for the sidebar, which is the binding whose five silent '
        .'siblings this guard was written after. A composer bound to a view that does not exist '
        .'never fires and never complains. Found: '.implode(', ', $composerBindings),
    );
});

it('reads each channel a view is named through, and no method that merely shares the name', function (): void {
    $planted = <<<'PHP'
        <?php
        $factory->composer('shell::livewire.app-sidebar', SidebarComposer::class);
        Route::view('/legal/terms', 'core::legal.terms');
        return response()->view('core::errors.offline');
        return view('ledger::livewire.transactions');
        $this->view('not-a-helper');
        Something::view('also-not-a-helper');
        $rendered = $renderer->view('nor-this');
        PHP;

    $names = array_column(viewReferencesInSource($planted, 'planted.php', viewReferencePatterns()), 'name');
    sort($names);

    expect($names)->toBe(
        [
            'core::errors.offline',
            'core::legal.terms',
            'ledger::livewire.transactions',
            'shell::livewire.app-sidebar',
        ],
        'The reader must find all four channels a view is named through, and must not read '
        .'`$obj->view()`, `Foo::view()` or a method declaration called view() as one — the bare '
        .'helper is the only one of those that resolves a view name.',
    );

    $blade = <<<'BLADE'
        @extends('layouts.app')
        @include('core::partials.header')
        @includeWhen($showFooter, 'core::partials.footer')
        @includeIf('core::partials.optional')
        BLADE;

    $bladeNames = array_column(viewReferencesInSource($blade, 'planted.blade.php', viewReferenceBladePatterns()), 'name');
    sort($bladeNames);

    expect($bladeNames)->toBe(
        [
            'core::partials.footer',
            'core::partials.header',
            'core::partials.optional',
            'layouts.app',
        ],
        'The reader must find every Blade include directive, conditional ones included — an @includeWhen '
        .'names its view in the second argument and a pattern reading the first finds nothing there.',
    );
});
