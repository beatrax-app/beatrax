<?php

declare(strict_types=1);

namespace Modules\FX\Public\Contracts;

use Modules\FX\Public\Exceptions\RateFetchException;

interface RateProvider
{
    // Stable lowercase key ('ecb', 'frankfurter', 'bundled'), persisted
    // as the `source` column value in `exchange_rates` so audit paths
    // can trace which provider supplied a given rate.
    public function key(): string;

    // Higher value = tried earlier.
    // ECB=200, Frankfurter=100, Bundled=0.
    public function priority(): int;

    /**
     * @return array{date: string, rates: array<string, string>}
     *                                                           `date`  — ISO 8601 date string (YYYY-MM-DD) for the rate set.
     *                                                           `rates` — map of quote currency code (e.g. 'USD') to rate as a
     *                                                           decimal string (e.g. '1.08530000'); never float.
     *
     * @throws RateFetchException when the provider cannot return rates
     *                            (network error, unexpected response format, etc.).
     */
    public function fetch(): array;
}
