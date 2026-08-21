<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// The source_product column and the parser contracts stay string; this enum is
// the one canonical spelling callers map through.
enum MigrationSourceProduct: string
{
    case Ynab4 = 'ynab4';

    case Nynab = 'nynab';

    case Actual = 'actual';
}
