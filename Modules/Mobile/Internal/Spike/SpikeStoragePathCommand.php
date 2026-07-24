<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Spike;

use Illuminate\Console\Command;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
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

        $this->table(
            ['signal', 'value'],
            [
                ['getenv(NATIVEPHP_STORAGE_PATH)', $this->render($storageEnv)],
                ['getenv(NATIVEPHP_PLATFORM)', $this->render($platformEnv)],
                ['laravel storagePath()', $this->getLaravel()->storagePath()],
                ['UserDataPathService::databasePath()', $this->paths->databasePath()],
                ['UserDataPathService::storagePath()', $this->paths->storagePath()],
            ],
        );

        return self::SUCCESS;
    }

    private function render(string|false $value): string
    {
        return $value === false ? '(unset)' : $value;
    }
}
