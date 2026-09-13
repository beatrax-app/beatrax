<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;
use Tests\Contracts\Support\WalkCensus;

// Every walk in tests/Contracts asserts a floor before it reads a verdict, and
// that floor catches exactly one failure: a walk that read nothing. Fifteen
// guards were planted with one realistic narrowing each and thirteen stayed
// green — with a real violation sitting in the region they stopped reading.
// WalkCensus is the second reader those floors now stand beside, and a reader
// that cannot go red says nothing, so it is driven here against planted lists
// rather than only against the tree.
/**
 * @link ../../.docs/conventions/arch-invariants.md#a-floor-does-not-notice-a-module-going-missing
 */
it('names the modules a walk reached no file in', function (): void {
    $whole = RepoTree::relativeFiles(RepoTree::PRODUCTION_PHP);

    expect(WalkCensus::modulesMissedBy($whole))->toBe(
        [],
        'The production scope reaches every module today, so this control has to read that before it is worth '
        .'anything as a control.'
    );

    $narrowed = array_values(array_filter(
        $whole,
        static fn (string $relative): bool => ! str_starts_with($relative, 'Modules/Ledger/'),
    ));

    expect(WalkCensus::modulesMissedBy($narrowed))->toBe(
        ['Ledger'],
        'A walk with one module filtered out of it has to be reported as missing exactly that module.'
    );

    // The floor the census stands beside, on the same two lists: it does not
    // move, which is the whole reason the census exists.
    expect(count($narrowed))->toBeGreaterThan(5000)
        ->and(count($whole))->toBeGreaterThan(5000);
});

it('reads the modules holding a file of the kind asked for, and no others', function (): void {
    $php = WalkCensus::modulesHolding('.php');
    $blade = WalkCensus::modulesHolding('.blade.php');
    $livewire = WalkCensus::modulesHolding('.php', '/Http/Livewire/');

    expect(count($php))->toBeGreaterThan(
        30,
        'Only '.count($php).' modules were read as holding PHP, which is too few to be this repository.'
    );

    // Each narrower question has to answer with a subset. A fragment or a
    // suffix the reader ignored would answer the same list every time, and a
    // census that always answers the same list excuses every narrowing.
    expect(array_diff($blade, $php))->toBe([], 'A module holding a template but no PHP is not a shape this tree has.');
    expect(array_diff($livewire, $php))->toBe([], 'A module holding a Livewire component but no PHP is not a shape this tree has.');
    expect(count($blade))->toBeLessThan(count($php), 'Four modules ship no template at all, so the template census cannot equal the PHP one.');
    expect(count($livewire))->toBeLessThan(count($php), 'Four modules ship no Livewire component, so that census cannot equal the PHP one either.');
});

it('counts a walk by the root each file sits under', function (): void {
    $counts = WalkCensus::byRoot([
        'Modules/Ledger/Internal/Thing.php',
        RepoTree::root().'/Modules/Core/Public/Other.php',
        'resources/views/errors/404.blade.php',
    ]);

    expect($counts)->toBe(
        ['Modules' => 2, 'resources' => 1],
        'The count is read off the first path segment, with an absolute path relative to the repository root first.'
    );
});
