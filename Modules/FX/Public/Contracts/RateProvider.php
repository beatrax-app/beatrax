<?php

declare(strict_types=1);

namespace Modules\FX\Public\Contracts;

use Modules\FX\Public\Exceptions\RateFetchException;

interface RateProvider
{
    // Persisted as the `source` column in `exchange_rates`, so a rate can be
    // traced back to the provider that supplied it.
    public function key(): string;

    // Higher is tried earlier: ECB=200, Frankfurter=100, Bundled=0.
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
