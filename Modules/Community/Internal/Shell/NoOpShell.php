<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Shell;

use Native\Desktop\Contracts\Shell;
use Psr\Log\LoggerInterface;

/**
 * No-op fallback binding for `Native\Desktop\Contracts\Shell` consumed
 * when the bundle runs outside the NativePHP runtime (local dev mode,
 * CI test runs). The four contract methods log the would-be call at
 * `info` level so a developer can confirm the integration plumbing
 * fires correctly without an Electron host present. Only OpenExternal
 * is actually used by 16.1; the other three methods are kept stub-
 * silent so the contract surface stays satisfiable.
 *
 * The binding is conditional in CommunityServiceProvider — when
 * NativePHP's own NativeServiceProvider has already bound the real
 * implementation the fallback is skipped. Feature tests substitute a
 * ShellFake via `$this->app->instance(Shell::class, $fake)` to assert
 * the recorded URL.
 */
final class NoOpShell implements Shell
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function showInFolder(string $path): void
    {
        $this->logger->info('NoOpShell: would show path in folder', ['path' => $path]);
    }

    public function openFile(string $path): string
    {
        $this->logger->info('NoOpShell: would open file', ['path' => $path]);

        return '';
    }

    public function trashFile(string $path): void
    {
        $this->logger->info('NoOpShell: would trash file', ['path' => $path]);
    }

    public function openExternal(string $url): void
    {
        $this->logger->info('NoOpShell: would launch URL', ['url' => $url]);
    }
}
