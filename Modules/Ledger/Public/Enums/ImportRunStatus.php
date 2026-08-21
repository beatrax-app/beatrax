<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// `confirmed` is terminal and is the file-layer idempotency key; `discarded`
// is re-previewable. The column stays string; this enum is the one canonical
// spelling every caller maps through.
enum ImportRunStatus: string
{
    case Previewed = 'previewed';

    case Confirmed = 'confirmed';

    case Discarded = 'discarded';
}
