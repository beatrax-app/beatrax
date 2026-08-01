<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Enums;

// The beatrax entity a migration conflict/change is keyed to — written by the
// three-way merge resolver, read by EntityChangeApplier to route a change onto
// the right table. The column stays string; this enum is the one canonical
// spelling every caller maps through. (`payee` is a separate grouping key.)
enum MigrationEntityType: string
{
    case BudgetAssignment = 'budget_assignment';

    case Category = 'category';

    case Account = 'account';

    case Transaction = 'transaction';
}
