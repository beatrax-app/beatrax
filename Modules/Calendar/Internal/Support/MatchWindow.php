<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Support;

// The slack in days between a calendar entry and an occurrence still counted
// as the same payment. OccurrenceMatcher widens its query by it and
// SeriesEntryPlacer subtracts it from a series' inception; the two numbers
// have to agree or an entry is placed just outside the window that reads it.
final class MatchWindow
{
    public const int DAYS = 7;
}
