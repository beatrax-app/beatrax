<?php

declare(strict_types=1);

use Modules\Core\Public\Navigation\Destination;
use Modules\Shell\Public\Navigation\AppNavigation;

// The page that answers "where is my data?" was reachable only by typing
// /help/data-locations: no rail row, no palette entry, no link from settings.
// A promise the application keeps somewhere nobody can navigate to is a promise
// the reader has to already know about.

it('carries the data-locations screen as a navigation destination', function (): void {
    $destinations = AppNavigation::destinations();

    expect(count($destinations))->toBeGreaterThan(10);

    $ids = array_map(
        static fn (object $destination): Destination => $destination->id,
        $destinations,
    );

    expect($ids)->toContain(Destination::DataLocations);
});

it('points that row at the route the page is actually served on', function (): void {
    expect(Destination::DataLocations->routeName())->toBe('core.help.data-locations')
        ->and(app('router')->has('core.help.data-locations'))->toBeTrue();
});
