<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Middleware;

// How often sync work may run on a request tail. Short, because this work only
// advances as fast as requests arrive: at one slice per tick a two-year ledger
// is minutes of ordinary use, not days. Long enough that a screen polling every
// second buys one slice rather than one per poll.
final class TailSweepInterval
{
    public const int SECONDS = 2;
}
