<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// Table is the absence of a chart, not a fourth chart: the data table renders
// under every case, so only the other three select an ApexCharts partial. The
// values reach the reader as `?viz=` and as a stored saved_reports.definition
// key, so they cannot be renamed.
enum ReportViz: string
{
    case Table = 'table';

    case Bar = 'bar';

    case Line = 'line';

    case Donut = 'donut';
}
