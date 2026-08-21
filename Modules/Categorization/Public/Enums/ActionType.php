<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

// Case order is the order the form offers an unused type in when a new action
// row is added.
enum ActionType: string
{
    case Category = 'category';

    case Counterparty = 'counterparty';

    case Note = 'note';

    case TaxTag = 'tax_tag';
}
