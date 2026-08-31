<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class IcsPdfStartingBalanceDetector extends StatementSummaryStartingBalanceDetector
{
    protected function sourceFormat(): string
    {
        return SourceFormat::IcsPdf->value;
    }
}
