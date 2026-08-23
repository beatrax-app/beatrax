<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// The four kinds of row migration_staging_unmapped_items holds, matching the
// vocabulary the staging migration names on the column and the four group
// labels in migration::preview.groups. The column has no CHECK trigger, so
// this enum is the only canonical spelling callers map through.
enum UnmappedItemType: string
{
    case Category = 'category';

    case Payee = 'payee';

    case Extra = 'extra';

    case Conflict = 'conflict';
}
