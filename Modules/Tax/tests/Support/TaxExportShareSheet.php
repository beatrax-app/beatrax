<?php

declare(strict_types=1);

namespace Modules\Tax\Tests\Support;

use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

final class TaxExportShareSheet extends ShareSheetExport
{
    /** @var array<string, string> */
    public array $handed = [];

    public function __construct(private readonly bool $dropsDownloads = true) {}

    public function replacesWebViewDownload(): bool
    {
        return $this->dropsDownloads;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function export(
        string $filename,
        string $contents,
        ?string $shareTitle = null,
        ?string $shareMessage = null,
    ): FileExportOutcome {
        $this->handed[$filename] = $contents;

        return FileExportOutcome::Shared;
    }
}
