<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\FX\Internal\Exceptions\AllProvidersFailed;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

/**
 * @param  array{date: string, rates: array<string, string>}  $result
 */
function makeFakeProvider(string $key, int $priority, ?array $result = null, bool $throws = false): RateProvider
{
    return new class($key, $priority, $result, $throws) implements RateProvider
    {
        public function __construct(
            private readonly string $k,
            private readonly int $p,
            /** @var array{date: string, rates: array<string, string>}|null */
            private readonly ?array $result,
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

        public function fetch(): array
        {
            if ($this->throws) {
                throw new RateFetchException("Provider {$this->k} failed.");
            }

            return $this->result ?? ['date' => '2026-06-05', 'rates' => ['USD' => '1.1359']];
        }
    };
}

describe('RateProviderRegistry', function (): void {
    it('returns result from the highest-priority provider that succeeds', function (): void {
        $ecb = makeFakeProvider('ecb', 200, ['date' => '2026-06-05', 'rates' => ['USD' => '1.1359']]);
        $frankfurter = makeFakeProvider('frankfurter', 100, ['date' => '2026-06-05', 'rates' => ['USD' => '1.1400']]);
        $bundled = makeFakeProvider('bundled', 0, ['date' => '2026-06-01', 'rates' => ['USD' => '1.1000']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(0);
        $cache->shouldReceive('forget')->with('fx.circuit.ecb.failures');

        $registry = new RateProviderRegistry([$ecb, $frankfurter, $bundled], $cache);

        $result = $registry->fetchCurrentRates();

        expect($result['provider'])->toBe('ecb')
            ->and($result['rates']['USD'])->toBe('1.1359');
    });

    it('falls back to Frankfurter when ECB fails', function (): void {
        $ecb = makeFakeProvider('ecb', 200, throws: true);
        $frankfurter = makeFakeProvider('frankfurter', 100, ['date' => '2026-06-05', 'rates' => ['USD' => '1.1400']]);
        $bundled = makeFakeProvider('bundled', 0, ['date' => '2026-06-01', 'rates' => ['USD' => '1.1000']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.frankfurter.failures', 0)->andReturn(0);
        $cache->shouldReceive('put')->with('fx.circuit.ecb.failures', 1, Mockery::any());
        $cache->shouldReceive('forget')->with('fx.circuit.frankfurter.failures');

        $registry = new RateProviderRegistry([$ecb, $frankfurter, $bundled], $cache);

        $result = $registry->fetchCurrentRates();

        expect($result['provider'])->toBe('frankfurter');
    });

    it('falls back to bundled when both ECB and Frankfurter fail', function (): void {
        $ecb = makeFakeProvider('ecb', 200, throws: true);
        $frankfurter = makeFakeProvider('frankfurter', 100, throws: true);
        $bundled = makeFakeProvider('bundled', 0, ['date' => '2026-06-01', 'rates' => ['USD' => '1.1000']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.frankfurter.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.bundled.failures', 0)->andReturn(0);
        $cache->shouldReceive('put')->with('fx.circuit.ecb.failures', 1, Mockery::any());
        $cache->shouldReceive('put')->with('fx.circuit.frankfurter.failures', 1, Mockery::any());
        $cache->shouldReceive('forget')->with('fx.circuit.bundled.failures');

        $registry = new RateProviderRegistry([$ecb, $frankfurter, $bundled], $cache);

        $result = $registry->fetchCurrentRates();

        expect($result['provider'])->toBe('bundled');
    });

    it('throws AllProvidersFailed when every provider fails', function (): void {
        $ecb = makeFakeProvider('ecb', 200, throws: true);
        $frankfurter = makeFakeProvider('frankfurter', 100, throws: true);
        $bundled = makeFakeProvider('bundled', 0, throws: true);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.frankfurter.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.bundled.failures', 0)->andReturn(0);
        $cache->shouldReceive('put')->with('fx.circuit.ecb.failures', 1, Mockery::any());
        $cache->shouldReceive('put')->with('fx.circuit.frankfurter.failures', 1, Mockery::any());
        $cache->shouldReceive('put')->with('fx.circuit.bundled.failures', 1, Mockery::any());

        $registry = new RateProviderRegistry([$ecb, $frankfurter, $bundled], $cache);

        expect(fn () => $registry->fetchCurrentRates())->toThrow(AllProvidersFailed::class);
    });

    it('skips a provider whose circuit-breaker failure count >= 3', function (): void {
        $ecb = makeFakeProvider('ecb', 200, throws: true);
        $bundled = makeFakeProvider('bundled', 0, ['date' => '2026-06-01', 'rates' => ['USD' => '1.1000']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(3);
        $cache->shouldReceive('get')->with('fx.circuit.bundled.failures', 0)->andReturn(0);
        $cache->shouldReceive('forget')->with('fx.circuit.bundled.failures');

        $registry = new RateProviderRegistry([$ecb, $bundled], $cache);

        $result = $registry->fetchCurrentRates();

        expect($result['provider'])->toBe('bundled');
    });

    it('resets circuit-breaker counter on provider success', function (): void {
        $ecb = makeFakeProvider('ecb', 200, ['date' => '2026-06-05', 'rates' => ['USD' => '1.1359']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(2);
        $cache->shouldReceive('forget')->with('fx.circuit.ecb.failures')->once();

        $registry = new RateProviderRegistry([$ecb], $cache);

        $registry->fetchCurrentRates();
    });

    it('returns supportedKeys() listing all provider keys', function (): void {
        $ecb = makeFakeProvider('ecb', 200);
        $frankfurter = makeFakeProvider('frankfurter', 100);
        $bundled = makeFakeProvider('bundled', 0);

        $cache = Mockery::mock(CacheRepository::class);

        $registry = new RateProviderRegistry([$ecb, $frankfurter, $bundled], $cache);

        expect($registry->supportedKeys())->toBe(['ecb', 'frankfurter', 'bundled']);
    });
});
