<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Enums;

// The lifecycle of a migration_runs row: a fresh parse is `parsed`, a
// promoted one is `confirmed`, one with unresolved conflicts is
// `needs_attention`, and an abandoned one is `discarded`. The column stays
// string; this enum is the one canonical spelling every caller maps through.
enum MigrationRunStatus: string
{
    case Parsed = 'parsed';

    case Confirmed = 'confirmed';

    case NeedsAttention = 'needs_attention';

    case Discarded = 'discarded';
}
