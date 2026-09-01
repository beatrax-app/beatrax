<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

// The source_format values no parser produces. Ingestion's SourceFormat holds
// the parsed formats and cannot hold these: a hand-entered row has no adapter
// and no preset. The cash book and CanonicalTransaction's cleared-status
// branch both read the column, so the value needs one spelling.
enum SyntheticSourceFormat: string
{
    case Manual = 'manual';

    // The demo seed hangs its rows off an import run for want of anywhere else
    // to put them, and no parser produced those either. Naming it here is what
    // lets the results screen tell an import from a container.
    case Demo = 'demo';
}
