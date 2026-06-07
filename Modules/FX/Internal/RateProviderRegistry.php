<?php

declare(strict_types=1);

namespace Modules\FX\Internal;

use Illuminate\Contracts\Cache\Repository;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\AllProvidersFailed;
use Modules\FX\Public\Exceptions\RateFetchException;

/**
 * Priority-ordered rate provider registry with a simple cache-backed circuit-breaker.
 *
 * Mirrors `Modules\Receipts\Internal\MatcherRegistry` exactly:
 *  - `private readonly array $providers` sorted by `priority()` DESC at injection time.
 *  - `supportedKeys()` mirrors the Receipts pattern verbatim.
 *  - `fetchCurrentRates()` replaces `dispatch()` and annotates the result with
 *    the winning provider's key.
 *
 * Circuit-breaker (D-05):
 *  - Each provider's failure count is persisted in the Laravel cache under the key
 *    `fx.circuit.{key}.failures`.
 *  - A provider with >= 3 cached failures is skipped (circuit open) for 6 hours.
 *  - On success the counter is reset (circuit closed).
 *
 * If every provider in the chain fails (or is circuit-open), `AllProvidersFailed`
 * is thrown. Callers that need a safe result should catch it and fall back to the
 * passthrough / original-currency path (D-07).
 */
final class RateProviderRegistry
{
    private const int CIRCUIT_OPEN_THRESHOLD = 3;

    /** @param list<RateProvider> $providers Sorted by priority() DESC at injection time. */
    public function __construct(
        private readonly array $providers,
        private readonly Repository $cache,
    ) {}

    /**
     * Try each provider in priority order, skipping those whose circuit is open.
     * Returns the first successful result annotated with the provider's key.
     *
     * @return array{date: string, rates: array<string, string>, provider: string}
     *
     * @throws AllProvidersFailed when every provider is exhausted or circuit-broken.
     */
    public function fetchCurrentRates(): array
    {
        $lastException = null;

        foreach ($this->providers as $provider) {
            if ($this->isCircuitOpen($provider->key())) {
                continue;
            }

            try {
                $result = $provider->fetch();
                $this->resetCircuit($provider->key());

                return array_merge($result, ['provider' => $provider->key()]);
            } catch (RateFetchException $e) {
                $this->recordFailure($provider->key());
                $lastException = $e;
            }
        }

        throw new AllProvidersFailed(
            'All FX rate providers failed or their circuits are open.',
            0,
            $lastException,
        );
    }

    /**
     * @return list<string>
     */
    public function supportedKeys(): array
    {
        return array_map(
            static fn (RateProvider $p): string => $p->key(),
            $this->providers,
        );
    }

    private function isCircuitOpen(string $providerKey): bool
    {
        return (int) ($this->cache->get("fx.circuit.{$providerKey}.failures", 0)) >= self::CIRCUIT_OPEN_THRESHOLD;
    }

    private function recordFailure(string $providerKey): void
    {
        $failures = (int) ($this->cache->get("fx.circuit.{$providerKey}.failures", 0)) + 1;
        $this->cache->put("fx.circuit.{$providerKey}.failures", $failures, now()->addHours(6));
    }

    private function resetCircuit(string $providerKey): void
    {
        $this->cache->forget("fx.circuit.{$providerKey}.failures");
    }
}
