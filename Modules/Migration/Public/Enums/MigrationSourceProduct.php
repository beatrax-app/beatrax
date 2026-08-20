<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Enums;

// The budgeting products a migration can import from. The
// migration_runs.source_product column and the parser contracts stay
// string; this enum is the one canonical spelling callers map through.
enum MigrationSourceProduct: string
{
    case Ynab4 = 'ynab4';

    case Nynab = 'nynab';

    case Actual = 'actual';
}
