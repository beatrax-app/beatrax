<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Public\Services\ShareSheetExport;

final class ShareSheetExportSpy extends ShareSheetExport
{
    /** @var list<array{string, string, string}> */
    public array $shared = [];

    public function __construct(private readonly bool $registersShareFile = true, private readonly bool $shareSucceeds = true) {}

    public function isAvailable(): bool
    {
        return true;
    }

    protected function canShareFiles(): bool
    {
        return $this->registersShareFile;
    }

    protected function share(string $shareTitle, string $shareMessage, string $path): bool
    {
        $this->shared[] = [$shareTitle, $shareMessage, $path];

        return $this->shareSucceeds;
    }
}
