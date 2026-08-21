<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// migration_staging_unmapped_items.resolution stays a nullable string column;
// NULL means the toggle was never touched and reads as KeepLocal.
enum ConflictResolution: string
{
    case KeepLocal = 'keep_local';

    case TakeSource = 'take_source';
}
