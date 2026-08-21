<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactoryContract;

// A composer bound to a view that does not exist never throws — it simply
// never fires. Five of them named the deleted top-nav and sat inert for a
// whole phase, so this reads the name back out of the source and asks the
// finder whether anything answers to it.

/** @return list<string> absolute paths to the PHP sources that name views */
function viewReferencePhpFiles(): array
{
    $files = [];
    foreach ([base_path('Modules'), base_path('app'), base_path('routes')] as $root) {
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
            // is missing, so the suite is not held to this.
            if (str_contains($path, '/tests/')) {
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
    $source = (string) file_get_contents($path);
    $found = [];

    foreach ($patterns as [$pattern, $group]) {
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[$group] as $match) {
            $found[] = [
                'file' => str_replace(base_path().'/', '', $path),
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
    expect($references)->not->toBe([]);

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
    // whatever the codebase did.
    expect($composerBindings)->toContain('shell::livewire.app-sidebar');
});
