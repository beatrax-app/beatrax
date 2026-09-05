<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

final class BackupShareSheet extends ShareSheetExport
{
    /** @var list<array{string, string}> */
    public array $handed = [];

    public function __construct(
        private readonly bool $dropsDownloads = true,
        private readonly bool $available = true,
        private readonly FileExportOutcome $outcome = FileExportOutcome::Shared,
    ) {}

    public function replacesWebViewDownload(): bool
    {
        return $this->dropsDownloads;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function exportFile(
        string $sourcePath,
        string $filename,
        ?string $shareTitle = null,
        ?string $shareMessage = null,
    ): FileExportOutcome {
        $this->handed[] = [$filename, (string) file_get_contents($sourcePath)];

        if ($this->outcome === FileExportOutcome::Shared) {
            @unlink($sourcePath);
        }

        return $this->outcome;
    }
}
