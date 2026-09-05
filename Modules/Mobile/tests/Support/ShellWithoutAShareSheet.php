<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Public\Services\ShareSheetExport;

final class ShellWithoutAShareSheet extends ShareSheetExport
{
    public bool $shareWasCalled = false;

    public function isAvailable(): bool
    {
        return true;
    }

    protected function canShareFiles(): bool
    {
        return false;
    }

    // Stands in for Share::file(), which returns void on a real device whether
    // or not the shell registered the function behind it.
    protected function share(string $shareTitle, string $shareMessage, string $path): bool
    {
        $this->shareWasCalled = true;

        return true;
    }
}
