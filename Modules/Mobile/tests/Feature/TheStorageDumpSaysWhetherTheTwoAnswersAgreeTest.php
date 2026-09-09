<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\Mobile\Internal\Spike\SpikeStoragePathCommand;

// This command printed the framework's storage root and the path service's
// side by side from the day it was written, and the two differing on iOS is
// the defect that shipped anyway: a reader scanning six paths has no reason to
// compare two of them by eye. The verdict row is that comparison, stated.

function runStorageDump(): string
{
    Artisan::registerCommand(app(SpikeStoragePathCommand::class));
    Artisan::call('mobile:spike-storage');

    return Artisan::output();
}

it('says the two answers agree when they do', function (): void {
    // The suite points NATIVEPHP_STORAGE_PATH and useStoragePath() at one
    // isolated root, which is the shape a correct device also has.
    expect(runStorageDump())->toContain('the two agree')
        ->and(runStorageDump())->toContain('yes');
});

it('names two storage roots when the shell announced one the service did not read', function (): void {
    $announced = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-announced-'.bin2hex(random_bytes(6));
    $original = $this->app->storagePath();

    $this->app->useStoragePath($announced);

    try {
        $output = runStorageDump();

        expect($output)->toContain('NO — two storage roots')
            ->and($output)->toContain($announced);
    } finally {
        $this->app->useStoragePath($original);
    }
});

// The announcement is read the way UserDataPathService reads it, because a
// bare getenv() is blind to Android's server-const injection.
it('reports the announced root from a server const, not only from the process environment', function (): void {
    $announced = '/data/user/0/com.beatrax.mobile/files/persisted_data/storage';
    $_SERVER['LARAVEL_STORAGE_PATH'] = $announced;

    try {
        expect(runStorageDump())->toContain($announced);
    } finally {
        unset($_SERVER['LARAVEL_STORAGE_PATH']);
    }
});

it('says (unset) rather than an empty cell for a signal no shell supplied', function (): void {
    $original = getenv('NATIVEPHP_PLATFORM');
    putenv('NATIVEPHP_PLATFORM');

    try {
        expect(runStorageDump())->toContain('(unset)');
    } finally {
        $original === false
            ? putenv('NATIVEPHP_PLATFORM')
            : putenv('NATIVEPHP_PLATFORM='.$original);
    }
});
