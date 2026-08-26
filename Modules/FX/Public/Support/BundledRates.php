<?php

declare(strict_types=1);

namespace Modules\FX\Public\Support;

// The `source` the bundled snapshot writes, and the one value of that column
// that is not a live feed: it ships with the app, so a row from any real
// provider for the same pair and day outranks it.
final class BundledRates
{
    public const SOURCE = 'bundled';
}
