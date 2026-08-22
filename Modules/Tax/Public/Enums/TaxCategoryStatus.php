<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Enums;

// The lifecycle of a tax_deduction_categories row: `active` (the column
// default) until the user archived it. The column stays string with no
// trigger, so this enum is the only canonical spelling callers map through.
enum TaxCategoryStatus: string
{
    case Active = 'active';

    case Archived = 'archived';
}
