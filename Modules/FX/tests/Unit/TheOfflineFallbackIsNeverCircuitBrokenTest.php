<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;
use Modules\FX\Public\Support\BundledRates;

// The circuit breaker was applied to every provider in the chain, the offline
// snapshot included. Its failures are not transient -- a file either reads or
// it does not -- so counting three of them only takes the fallback out of the
// chain for six hours at exactly the moment the network providers are failing,
// which is the one moment it exists for.

function fxChainProvider(string $key, int $priority, bool $online, bool $throws): RateProvider
{
    return new class($key, $priority, $online, $throws) implements RateProvider
    {
        public function __construct(
            private readonly string $k,
            private readonly int $p,
            private readonly bool $online,
            private readonly bool $throws,
        ) {}

        public function key(): string
        {
            return $this->k;
        }

        public function priority(): int
        {
            return $this->p;
        }

        public function reachesTheNetwork(): bool
        {
            return $this->online;
        }

        /**
         * @return array{date: string, rates: array<string, string>}
         */
        public function fetch(): array
        {
            if ($this->throws) {
                throw new RateFetchException(sprintf('Provider %s failed.', $this->k));
            }

            return ['date' => '2026-06-05', 'rates' => ['USD' => '1.1359']];
        }
    };
}

it('still answers from the bundled snapshot after three of its own failures', function (): void {
    $cache = new Repository(new ArrayStore);
    $cache->put('fx.circuit.'.BundledRates::SOURCE.'.failures', 3, 3600);

    $registry = new RateProviderRegistry([
        fxChainProvider('ecb', 200, online: true, throws: true),
        fxChainProvider('frankfurter', 100, online: true, throws: true),
        fxChainProvider(BundledRates::SOURCE, 0, online: false, throws: false),
    ], $cache);

    expect($registry->fetchCurrentRates()['provider'])->toBe(BundledRates::SOURCE);
});

it('counts no failure against a provider that reaches no network', function (): void {
    $cache = new Repository(new ArrayStore);

    $registry = new RateProviderRegistry([
        fxChainProvider(BundledRates::SOURCE, 0, online: false, throws: true),
    ], $cache);

    try {
        $registry->fetchCurrentRates();
    } catch (Throwable) {
        // The empty chain throwing is the point of the arrangement, not of
        // the assertion below it.
    }

    expect($cache->get('fx.circuit.'.BundledRates::SOURCE.'.failures'))->toBeNull();
});
