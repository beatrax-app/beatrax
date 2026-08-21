<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class Camt053StartingBalanceDetector extends StatementSummaryStartingBalanceDetector
{
    protected function sourceFormat(): string
    {
        return SourceFormat::Camt053->value;
    }
}
