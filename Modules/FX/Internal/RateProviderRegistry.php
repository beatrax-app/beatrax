<?php

declare(strict_types=1);

namespace Modules\FX\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\AllProvidersFailed;
use Modules\FX\Public\Exceptions\RateFetchException;

/**
 * @link ../../../.docs/features/fx/architecture.md
 */
final class RateProviderRegistry
{
    use CoercesScalars;

    private const int CIRCUIT_OPEN_THRESHOLD = 3;

    private const int CIRCUIT_OPEN_TTL_HOURS = 6;

    /** @param list<RateProvider> $providers Sorted by priority() DESC at injection time. */
    public function __construct(
        private readonly array $providers,
        private readonly Repository $cache,
    ) {}

    /**
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
        $raw = $this->cache->get("fx.circuit.{$providerKey}.failures", 0);

        return self::toInt($raw) >= self::CIRCUIT_OPEN_THRESHOLD;
    }

    private function recordFailure(string $providerKey): void
    {
        $cacheKey = "fx.circuit.{$providerKey}.failures";
        $current = self::toInt($this->cache->get($cacheKey, 0));

        if ($current === 0) {
            $this->cache->put($cacheKey, 1, CarbonImmutable::now()->addHours(self::CIRCUIT_OPEN_TTL_HOURS));

            return;
        }

        // Subsequent failure — bump the count WITHOUT resetting the TTL
        // (increment preserves the existing expiry). Otherwise a provider that
        // fails more often than once per 6h would slide its window forever and
        // the circuit would never auto-heal after the outage ends.
        $this->cache->increment($cacheKey);
    }

    private function resetCircuit(string $providerKey): void
    {
        $this->cache->forget("fx.circuit.{$providerKey}.failures");
    }
}
