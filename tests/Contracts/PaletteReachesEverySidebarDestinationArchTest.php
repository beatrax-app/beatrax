<?php

declare(strict_types=1);

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\Shell\Public\Navigation\AppNavigation;
use Modules\Shell\Public\Navigation\Destination;

// The sidebar and the command palette are two renderings of one roster. They
// were two hand-kept lists, and the palette fell thirteen destinations behind
// without anything noticing: no Budgets, no Goals, no Pots, no Reports. This
// reads the rail back out of its own template and asks the palette whether it
// can reach the same places.

function sidebarTemplateSource(): string
{
    // mobile-app/ is a second composer root whose Modules/ is a symlink onto
    // this tree, so resolving it gives the one root both roots agree on.
    $repoRoot = dirname((string) realpath(base_path('Modules')));
    $path = $repoRoot.'/Modules/Shell/Resources/views/livewire/app-sidebar.blade.php';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array<string, string> Destination case name => palette id */
function destinationIdsByCaseName(): array
{
    $byName = [];
    foreach (Destination::cases() as $case) {
        $byName[$case->name] = $case->value;
    }

    return $byName;
}

/** @return list<string> every destination the sidebar template names, as palette ids */
function sidebarDestinationIds(): array
{
    $byName = destinationIdsByCaseName();

    $ids = [];
    if (preg_match_all('/Destination::([A-Za-z][A-Za-z0-9]*)/', sidebarTemplateSource(), $matches) !== 0) {
        foreach (array_unique($matches[1]) as $caseName) {
            $id = $byName[$caseName] ?? null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }
    }
    sort($ids);

    return array_values(array_unique($ids));
}

/** @return list<string> every navigation id the registry carries, dev rows included */
function paletteNavigationIds(): array
{
    /** @var NavigationRegistry $registry */
    $registry = app(NavigationRegistry::class);

    $ids = [];
    foreach ($registry->all() as $entry) {
        $ids[] = $entry->id;
    }
    sort($ids);

    return $ids;
}

/** @return list<string> the navigation destinations the palette offers a non-developer */
function paletteDestinationIds(): array
{
    $ids = array_values(array_filter(
        paletteNavigationIds(),
        static fn (string $id): bool => ! str_starts_with($id, 'dev.'),
    ));
    sort($ids);

    return $ids;
}

it('offers the same destinations in the command palette as in the sidebar', function (): void {
    $sidebar = sidebarDestinationIds();
    $palette = paletteDestinationIds();

    // A scan that found nothing would agree with a palette that offers nothing,
    // so the roster has to be a roster before the comparison means anything.
    expect(count($sidebar))->toBeGreaterThan(20);
    expect(count($palette))->toBeGreaterThan(20);

    $absentFromPalette = array_values(array_diff($sidebar, $palette));
    $absentFromSidebar = array_values(array_diff($palette, $sidebar));

    expect($absentFromPalette)->toBe([], "The sidebar reaches a destination the command palette cannot. Add it to Modules/Shell/Public/Navigation/AppNavigation, or take the row out of the rail:\n  ".implode("\n  ", $absentFromPalette));
    expect($absentFromSidebar)->toBe([], "The command palette offers a destination the sidebar does not. Either the rail lost a row or the roster kept a screen nobody can navigate to:\n  ".implode("\n  ", $absentFromSidebar));
});

// Scanning for Destination:: only sees rows that go through the roster. A row
// added the old way — route() and a nav lang key, straight in the template —
// would be invisible to the comparison above, so it is banned outright.
it('sends every sidebar row through the shared navigation roster', function (): void {
    $source = sidebarTemplateSource();
    expect($source)->not->toBe('');

    // Sign out posts a form; it is an action, not a place.
    $allowed = ['logout'];

    $named = [];
    if (preg_match_all('/route\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) !== 0) {
        $named = array_values(array_unique($matches[1]));
    }

    $direct = array_values(array_diff($named, $allowed));
    expect($direct)->toBe([], "A sidebar row names a route directly instead of a Destination, so nothing can check it reaches the palette:\n  ".implode("\n  ", $direct));

    expect($source)->not->toContain("Lang::get('core::sidebar.nav.");
});

it('resolves every declared destination to a route', function (): void {
    $declared = array_values(destinationIdsByCaseName());
    sort($declared);

    $resolved = [];
    foreach (AppNavigation::destinations() as $destination) {
        $resolved[] = $destination->id->value;
    }
    sort($resolved);

    expect($declared)->not->toBe([]);
    expect($resolved)->toBe($declared);
});

// The comparison above drops dev.* rows. If the registry ever stopped carrying
// them the filter would quietly become a no-op, and a palette that leaks
// dev.sql to a non-developer is a worse bug than a palette missing Budgets.
it('keeps the developer rows in the registry for the payload filter to drop', function (): void {
    $devIds = array_values(array_filter(
        paletteNavigationIds(),
        static fn (string $id): bool => str_starts_with($id, 'dev.'),
    ));

    expect($devIds)->toContain('dev.overview');
    expect($devIds)->toContain('dev.sql');
    expect(paletteDestinationIds())->not->toContain('dev.sql');
});
