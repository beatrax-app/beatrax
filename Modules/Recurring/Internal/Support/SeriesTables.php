<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

// The aliases every series query joins on. They are here rather than private to
// one class because two read models now write the same joins, and an alias that
// disagrees between them is a query that silently reads the wrong column.
final class SeriesTables
{
    public const string OCCURRENCES = 'recurring_series_occurrences as o';

    public const string TRANSACTIONS = 'transactions as t';

    public const string SERIES = 'recurring_series as s';
}
