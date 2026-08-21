<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class Mt940StartingBalanceDetector extends StatementSummaryStartingBalanceDetector
{
    protected function sourceFormat(): string
    {
        return SourceFormat::Mt940->value;
    }
}
