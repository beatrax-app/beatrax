<?php

declare(strict_types=1);

// `db:restore` is only correct because `php artisan down` halts queue
// consumers. A supervisor with `force: true` keeps running in maintenance
// mode, so a job could re-open the SQLite file after `DB::purge()` and before
// the swap completes.

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
