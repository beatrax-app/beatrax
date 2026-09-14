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

        $directory = dirname($sentinel);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->logger?->error(
                'EnsureAppKey: the directory holding the first-launch marker could not be created, so the application key was left alone.',
                ['directory' => $directory],
            );

            return;
        }

        // Claimed before the rotation, not stamped after it: a key rotated on
        // an install that cannot record the rotation rotates again on every
        // launch, leaving the previous key's ciphertext unreadable. Failing to
        // claim costs the shipped key; failing to record a rotation costs data.
        if (@file_put_contents($sentinel, '') === false) {
            $this->logger?->error(
                'EnsureAppKey: the first-launch marker could not be written, so the application key was left alone rather than rotated again on every launch.',
                ['sentinel' => $sentinel],
            );

            return;
        }

        $before = $this->appKeyOnDisk();

        $this->artisan->call('key:generate', ['--force' => true]);

        // Read back from the file rather than trusting the command: Laravel's
        // key:generate does not check file_put_contents and returns success
        // either way, setting the new key in this process's config only. A
        // read-only .env therefore looks exactly like a successful rotation.
        if ($this->appKeyOnDisk() === $before) {
            // Released, so the next launch tries again. A claim that bought
            // nothing must not read back as a rotation that happened.
            @unlink($sentinel);

            $this->logger?->error(
                'EnsureAppKey: the application key was not written, so this installation is still using the key shipped in the bundle.',
                ['environment_file' => $this->environmentFile()],
            );
        }
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
