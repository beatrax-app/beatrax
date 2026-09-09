<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Spike;

use Illuminate\Console\Command;
use Modules\Core\Public\Services\UserDataPathService;

final class SpikeStoragePathCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:spike-storage';

    /** @var string */
    protected $description = 'Spike B: dump the NativePHP mobile storage-path signals and resolved user-data paths.';

    public function __construct(
        private readonly UserDataPathService $paths,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $storageEnv = getenv('NATIVEPHP_STORAGE_PATH');
        $platformEnv = getenv('NATIVEPHP_PLATFORM');

        // These two were already printed side by side, and the difference was
        // read past for as long as the table had six rows and no verdict. The
        // verdict row is the difference stated rather than left to the eye.
        $framework = $this->getLaravel()->storagePath();
        $service = $this->paths->storagePath();

        $this->table(
            ['signal', 'value'],
            [
                ['getenv(NATIVEPHP_STORAGE_PATH)', $this->render($storageEnv)],
                ['getenv(NATIVEPHP_PLATFORM)', $this->render($platformEnv)],
                ['LARAVEL_STORAGE_PATH (announced)', $this->render($this->announcedStorageRoot())],
                ['laravel storagePath()', $framework],
                ['UserDataPathService::databasePath()', $this->paths->databasePath()],
                ['UserDataPathService::storagePath()', $service],
                ['the two agree', $framework === $service ? 'yes' : 'NO — two storage roots'],
            ],
        );

        return self::SUCCESS;
    }

    // Read the way UserDataPathService reads it: Android hands its environment
    // to PHP as server consts, so a bare getenv() is blind to it there.
    private function announcedStorageRoot(): string|false
    {
        $announced = $_SERVER['LARAVEL_STORAGE_PATH']
            ?? $_ENV['LARAVEL_STORAGE_PATH']
            ?? getenv('LARAVEL_STORAGE_PATH');

        return is_string($announced) && $announced !== '' ? $announced : false;
    }

    private function render(string|false $value): string
    {
        return $value === false ? '(unset)' : $value;
    }
}
