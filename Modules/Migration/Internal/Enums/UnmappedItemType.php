<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// The two kinds of row migration_staging_unmapped_items holds, matching the
// vocabulary the staging migration names on the column and the two group
// labels in migration::preview.groups. The column has no CHECK trigger, so
// this enum is the only canonical spelling callers map through.
enum UnmappedItemType: string
{
    case Extra = 'extra';

    case Conflict = 'conflict';
}
