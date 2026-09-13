<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

// The aliases every series query joins on. They are here rather than private to
// one class because two read models now write the same joins, and an alias that
// disagrees between them is a query that silently reads the wrong column.
final class SeriesTables
{
    // Roots take this too, not only joins: the bounded-read guard resolves a
    // table through a constant now, so spelling the string out at a root buys
    // nothing. It still counts these reads -- a guard that cannot resolve the
    // constant reds them as "allows 1, found 0".
    public const string OCCURRENCES = 'recurring_series_occurrences as o';

    public const string TRANSACTIONS = 'transactions as t';

    public const string SERIES = 'recurring_series as s';
}
