<?php

declare(strict_types=1);

namespace Modules\FX\Public\Contracts;

use Modules\FX\Public\Exceptions\RateFetchException;

/**
 * Strategy interface implemented by every rate provider in the FX module.
 *
 * The RateProviderRegistry collects implementations via the
 * `fx.rate_provider` container tag and tries them in descending priority
 * order. ECB (priority 200) is tried first, Frankfurter (priority 100)
 * second, and the bundled offline snapshot (priority 0) is always
 * available as the final fallback.
 *
 * Providers are pure functions of their input: they hold no per-call
 * state and may be bound as singletons. Side effects (cache writes,
 * circuit-breaker updates) live downstream in ExchangeRateService.
 */
interface RateProvider
{
    /**
     * Stable lowercase key identifying this provider, e.g. 'ecb',
     * 'frankfurter', or 'bundled'. Persisted as the `source` column
     * value in `exchange_rates` so audit paths can trace which provider
     * supplied a given rate.
     */
    public function key(): string;

    /**
     * Higher value = tried earlier. ECB=200, Frankfurter=100, Bundled=0.
     */
    public function priority(): int;

    /**
     * Fetch current exchange rates from this provider.
     *
     * @return array{date: string, rates: array<string, string>}
     *                                                           `date`  — ISO-8601 date string (YYYY-MM-DD) for the rate set.
     *                                                           `rates` — map of quote currency code (e.g. 'USD') to rate as a
     *                                                           decimal string (e.g. '1.08530000'); never float.
     *
     * @throws RateFetchException when the provider cannot return rates
     *                            (network error, unexpected response format, etc.).
     */
    public function fetch(): array;
}
