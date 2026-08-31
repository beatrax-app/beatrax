<?php

declare(strict_types=1);

namespace App\Fixtures;

use Carbon\CarbonImmutable;

interface RebasesStatementDates
{
    public function handles(string $path, string $contents): bool;

    // The id SourceAdapterRegistry binds this file under, so a caller can read
    // the copy back through the adapter that will read it on import.
    public function format(string $contents): string;

    public function newestDate(string $contents): ?CarbonImmutable;

    public function rebase(string $contents, MonthShift $shift): StatementRebaseResult;
}
