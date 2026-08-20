<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Enums;

enum MigrationEntityType: string
{
    case BudgetAssignment = 'budget_assignment';

    case Category = 'category';

    case Account = 'account';

    case Transaction = 'transaction';
}
