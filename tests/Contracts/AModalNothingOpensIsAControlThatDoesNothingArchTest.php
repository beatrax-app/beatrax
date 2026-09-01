<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// A Flux modal is shown and hidden by name, through `modal-show` and
// `modal-close`. A component that closes one by name but that nothing anywhere
// shows by that name is a modal with no way in — its trigger fills the fields
// and the reader sees nothing happen.

// SuggestMappingModal was exactly that: cancel() and submit() both closed
// `suggest-mapping`, open() filled four fields and dispatched nothing, and the
// community feature's only entry point did nothing for all 149 rows.

/** @return array<string, list<string>> module => modal names it closes */
function modalNamesClosedPerModule(): array
{
    $closed = [];

    foreach (moduleSourceFiles('*.php') as $path => $contents) {
        $module = moduleOf($path);

        if ($module === null || preg_match_all("/'modal-close',\s*name:\s*'([^']+)'/", $contents, $m) !== 1 && ! isset($m[1])) {
            continue;
        }

        foreach ($m[1] ?? [] as $name) {
            $closed[$module][] = $name;
        }
    }

    return array_map(static fn (array $names): array => array_values(array_unique($names)), $closed);
}

/** @return array<string, string> path => contents */
function moduleSourceFiles(string $pattern): array
{
    $files = [];

    foreach ((new Finder)->files()->in(base_path('Modules'))->name($pattern)->notPath('tests') as $file) {
        $files[(string) $file->getRealPath()] = $file->getContents();
    }

    return $files;
}

function moduleOf(string $path): ?string
{
    $relative = str_replace(base_path().'/', '', $path);

    return preg_match('#^Modules/([^/]+)/#', $relative, $m) === 1 ? $m[1] : null;
}

// Every way this codebase opens one: the component's own modal-show, Flux's
// client-side show(), and the bottom sheet a phone gets instead. A name is
// reachable if any of them names it anywhere in the module.
/**
 * @return list<string>
 */
function modalOpenPatterns(string $name): array
{
    $quoted = preg_quote($name, '/');

    return [
        "/modal-show[^\n]{0,40}".$quoted."'/",
        "/\\\$flux\\.modal\\(\s*'".$quoted."'/",
        "/open-sheet[^\n]{0,40}".$quoted."'/",
    ];
}

function moduleShowsModal(string $module, string $name): bool
{
    // A name built at runtime ends in the separator its id is glued to; there
    // is no literal to match, so it is not something a static scan can answer.
    if (str_ends_with($name, '-')) {
        return true;
    }

    foreach (['*.php', '*.blade.php'] as $pattern) {
        foreach (moduleSourceFiles($pattern) as $path => $contents) {
            if (moduleOf($path) !== $module) {
                continue;
            }

            foreach (modalOpenPatterns($name) as $probe) {
                if (preg_match($probe, $contents) === 1) {
                    return true;
                }
            }
        }
    }

    return false;
}

it('opens every modal it knows how to close', function (): void {
    $closedPerModule = modalNamesClosedPerModule();

    // A scan that matched nothing would pass every assertion below it.
    expect($closedPerModule)->not->toBeEmpty('no component closes a modal by name — the scan is broken, not the code');

    $unopenable = [];

    foreach ($closedPerModule as $module => $names) {
        foreach ($names as $name) {
            if (! moduleShowsModal($module, $name)) {
                $unopenable[] = $module.' closes "'.$name.'" and nothing in it ever shows that modal';
            }
        }
    }

    expect($unopenable)->toBe([], implode("\n  ", ['A modal with no way in is a control that does nothing:', ...$unopenable]));
});
