<?php

declare(strict_types=1);

use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\Shell\Public\Navigation\AppNavigation;

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

/** @return list<string> every destination one template names, as palette ids */
function sidebarDestinationIdsIn(string $source): array
{
    $byName = destinationIdsByCaseName();

    $matches = PatternScan::all('/Destination::([A-Za-z][A-Za-z0-9]*)/', $source);

    $ids = [];
    foreach (array_unique($matches[1]) as $caseName) {
        $id = $byName[$caseName] ?? null;
        if ($id !== null) {
            $ids[] = $id;
        }
    }
    sort($ids);

    return array_values(array_unique($ids));
}

/** @return list<string> every destination the sidebar template names, as palette ids */
function sidebarDestinationIds(): array
{
    return sidebarDestinationIdsIn(sidebarTemplateSource());
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
    expect(count($sidebar))->toBeGreaterThan(20, 'The sidebar template yielded almost no destinations, so the comparison below is the reader being broken rather than the two rosters agreeing.');
    expect(count($palette))->toBeGreaterThan(20, 'The navigation registry yielded almost no destinations, so the comparison below is the registry being empty rather than the two rosters agreeing.');

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
    expect($source)->not->toBe('', 'The sidebar template was not found, so every assertion below reads an empty string as a clean rail.');

    // Sign out posts a form; it is an action, not a place.
    $allowed = ['logout'];

    $matches = PatternScan::all('/route\(\s*[\'"]([^\'"]+)[\'"]/', $source);
    $named = array_values(array_unique($matches[1]));

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

    expect($declared)->not->toBe([], 'The Destination enum declares no case, so the comparison below is vacuous.');
    expect($resolved)->toBe($declared, 'AppNavigation resolves a different set of destinations than the Destination enum declares. A case the roster does not build is a screen the palette offers and the router cannot reach; a destination the roster builds that the enum does not declare cannot be named by the sidebar template at all.');
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

it('reads a destination a template names and resolves nothing for one it does not', function (): void {
    $names = "<a wire:navigate href=\"{{ Destination::Dashboard->route() }}\">Dashboard</a>\n"
        .'<a wire:navigate href="{{ Destination::Transactions->route() }}">Transactions</a>';

    // Two near misses: a row that names its route directly is invisible to this
    // reader — which is why the rule above bans that shape outright — and a case
    // name the enum does not declare resolves to no id rather than to a guess.
    $direct = '<a wire:navigate href="{{ route(\'transactions.index\') }}">Transactions</a>';
    $unknown = '<a href="{{ Destination::NoSuchScreen->route() }}">?</a>';

    expect(sidebarDestinationIdsIn($names))->toBe(['dashboard', 'transactions.index'])
        ->and(sidebarDestinationIdsIn($direct))->toBe([])
        ->and(sidebarDestinationIdsIn($unknown))->toBe([]);
});
