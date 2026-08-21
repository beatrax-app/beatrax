<?php

declare(strict_types=1);

namespace Modules\Core\Public\Bootstrap;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Core\Public\Services\UserDataPathService;

final class EnsureAppKey
{
    public const SENTINEL_FILENAME = 'first-launch.app-key-generated';

    public function __construct(
        private readonly UserDataPathService $paths,
        private readonly ConsoleKernel $artisan,
    ) {}

    public function run(): void
    {
        $sentinel = $this->paths->appRelative(self::SENTINEL_FILENAME);

        if (is_file($sentinel)) {
            return;
        }

        $this->artisan->call('key:generate', ['--force' => true]);

        $directory = dirname($sentinel);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($sentinel, '');
    }
}
