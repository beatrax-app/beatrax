<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Support;

// The slack in days between an expected occurrence and the payment that counts
// as the same one. Every reader has to agree on it or a payment lands just
// outside the window the next one looks in — the calendar's occurrence match
// and the projection's booked-row supersession both read it.
final class MatchWindow
{
    public const int DAYS = 7;
}
