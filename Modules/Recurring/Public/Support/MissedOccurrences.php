<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Support;

// When a gap between two postings is a skipped occurrence rather than a period
// of its own, and how many may be skipped before the cluster names no cadence
// at all. The inferrer drops such an interval from the median it snaps on; the
// drift evaluator has one interval and reads the same bound off it.
final class MissedOccurrences
{
    public const float INTERVAL_MULTIPLIER = 1.8;

    public const int MAX_PER_WINDOW = 2;

    public const int WINDOW_SIZE = 6;
}
