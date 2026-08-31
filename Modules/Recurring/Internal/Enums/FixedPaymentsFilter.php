<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Enums;

// The dashboard card's date filter. Its values reach the URL through #[Url], so
// they are a public surface and belong here rather than as literals repeated
// between the component and the blade that highlights the active button.
enum FixedPaymentsFilter: string
{
    case All = 'all';

    case ThisMonth = 'this-month';

    public const string DEFAULT = 'all';

    public function labelKey(): string
    {
        return 'recurring::fixed_payments.filter_'.($this === self::All ? 'all' : 'this_month');
    }

    public function emptyKey(): string
    {
        return 'recurring::fixed_payments.empty_'.($this === self::All ? 'all' : 'this_month');
    }
}
