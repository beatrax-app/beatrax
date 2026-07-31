<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The lifecycle of an import_runs row: a fresh preview is `previewed`, a
// committed one is `confirmed` (terminal, the file-layer idempotency key),
// and an abandoned one is `discarded` (re-previewable). The column stays
// string; this enum is the one canonical spelling every caller maps through.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
enum ImportRunStatus: string
{
    case Previewed = 'previewed';

    case Confirmed = 'confirmed';

    case Discarded = 'discarded';
}
