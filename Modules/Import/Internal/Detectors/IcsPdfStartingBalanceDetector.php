<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Detectors;

final class IcsPdfStartingBalanceDetector extends StatementSummaryStartingBalanceDetector
{
    // A bare literal, not a SourceFormat case: ics-pdf is a separate
    // vocabulary from the banking statement formats that enum covers.
    protected function sourceFormat(): string
    {
        return 'ics-pdf';
    }
}
