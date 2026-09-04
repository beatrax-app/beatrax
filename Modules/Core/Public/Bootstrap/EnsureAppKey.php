<?php

declare(strict_types=1);

namespace Modules\Core\Public\Bootstrap;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\PatternScan;
use Psr\Log\LoggerInterface;

final readonly class EnsureAppKey
{
    public const string SENTINEL_FILENAME = 'first-launch.app-key-generated';

    public function __construct(
        private UserDataPathService $paths,
        private ConsoleKernel $artisan,
        private ?LoggerInterface $logger = null,
        // Resolved through the path service rather than the container:
        // Larastan models environmentFilePath() as static, and base_path()
        // outside UserDataPathService is a boundary violation.
        private ?string $environmentFile = null,
    ) {}

    public function run(): void
    {
        $sentinel = $this->paths->appRelative(self::SENTINEL_FILENAME);

        if (is_file($sentinel)) {
            return;
        }

        $before = $this->appKeyOnDisk();

        $this->artisan->call('key:generate', ['--force' => true]);

        // Read back from the file rather than trusting the command: Laravel's
        // key:generate does not check file_put_contents and returns success
        // either way, setting the new key in this process's config only. A
        // read-only .env therefore looks exactly like a successful rotation.
        if ($this->appKeyOnDisk() === $before) {
            $this->logger?->error(
                'EnsureAppKey: the application key was not written, so this installation is still using the key shipped in the bundle.',
                ['environment_file' => $this->environmentFile()],
            );

            return;
        }

        $directory = dirname($sentinel);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        // Written only once the key on disk has actually changed. Stamping it
        // regardless made a failed rotation permanent: the next launch reads
        // the bundled key back and this action never runs again.
        file_put_contents($sentinel, '');
    }

    private function environmentFile(): string
    {
        return $this->environmentFile ?? UserDataPathService::environmentFile();
    }

    private function appKeyOnDisk(): ?string
    {
        $path = $this->environmentFile();

        if (! is_file($path)) {
            return null;
        }

        $matched = PatternScan::first('/^APP_KEY=(.*)$/m', (string) file_get_contents($path));

        return $matched === [] ? null : trim($matched[1]);
    }
}
