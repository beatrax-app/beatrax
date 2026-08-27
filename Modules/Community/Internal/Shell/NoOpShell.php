<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Shell;

use Modules\Community\Public\Support\LoggableUrl;
use Native\Desktop\Contracts\Shell;
use Psr\Log\LoggerInterface;

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
        $this->logger->info('NoOpShell: would launch URL', ['url' => LoggableUrl::withoutQuery($url)]);
    }
}
