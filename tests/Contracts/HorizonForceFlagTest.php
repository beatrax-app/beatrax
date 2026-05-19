<?php

declare(strict_types=1);

// Locks the load-bearing assumption that maintenance mode pauses the
// Horizon workers. The `db:restore` correctness story relies on the
// fact that `php artisan down` halts queue consumers; this in turn
// relies on Horizon NOT being configured with `force: true` on any
// supervisor. A `force` supervisor keeps running even in maintenance
// mode, which would mean a queue job can re-open the SQLite file
// after `DB::purge()` and before the swap completes.
//
// The check is intentionally strict: a single supervisor with
// `'force' => true` in `config/horizon.php` makes this test fail with
// a message linking back to the db:restore correctness story.
//
// If `config/horizon.php` does not exist on the project (the project
// could decide to remove Horizon in a future variant), the test
// trivially passes — the assumption is vacuously true.

it('does not allow any Horizon supervisor to set force: true (HorizonForceFlagInvariant)', function (): void {
    $horizonConfigPath = base_path('config/horizon.php');
    if (! is_file($horizonConfigPath)) {
        expect(true)->toBeTrue();

        return;
    }

    $contents = (string) file_get_contents($horizonConfigPath);
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;

    expect($stripped)
        ->not->toMatch("/['\"]force['\"]\\s*=>\\s*true/");
});
