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
                throw new RateFetchException(sprintf('Provider %s failed.', $this->k));
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
        $cache->shouldReceive('add')->with('fx.circuit.ecb.failures', 1, Mockery::any())->andReturn(true);
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
        $cache->shouldReceive('add')->with('fx.circuit.ecb.failures', 1, Mockery::any())->andReturn(true);
        $cache->shouldReceive('add')->with('fx.circuit.frankfurter.failures', 1, Mockery::any())->andReturn(true);
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
        $cache->shouldReceive('add')->with('fx.circuit.ecb.failures', 1, Mockery::any())->andReturn(true);
        $cache->shouldReceive('add')->with('fx.circuit.frankfurter.failures', 1, Mockery::any())->andReturn(true);
        $cache->shouldReceive('add')->with('fx.circuit.bundled.failures', 1, Mockery::any())->andReturn(true);

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

    // The failure key is provider-global: two users' refresh jobs failing the
    // same provider both read 0 and both wrote 1, losing a failure and opening
    // the circuit a failure late. add() decides the create; the loser counts.
    it('counts a failure onto a count a rival already opened, rather than resetting it to one', function (): void {
        $ecb = makeFakeProvider('ecb', 200, throws: true);
        $bundled = makeFakeProvider('bundled', 0, ['date' => '2026-06-01', 'rates' => ['USD' => '1.1000']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.bundled.failures', 0)->andReturn(0);
        $cache->shouldReceive('forget')->with('fx.circuit.bundled.failures');
        $cache->shouldNotReceive('put');
        $cache->shouldReceive('add')->with('fx.circuit.ecb.failures', 1, Mockery::any())->once()->andReturn(false);
        $cache->shouldReceive('increment')->with('fx.circuit.ecb.failures')->once();

        $registry = new RateProviderRegistry([$ecb, $bundled], $cache);

        expect($registry->fetchCurrentRates()['provider'])->toBe('bundled');
    });

    // The reader whose job gets there first must not have its own failure
    // dropped either: add() answering true is the whole record of that one.
    it('opens the count with add rather than a write that could overwrite a rival', function (): void {
        $ecb = makeFakeProvider('ecb', 200, throws: true);
        $bundled = makeFakeProvider('bundled', 0, ['date' => '2026-06-01', 'rates' => ['USD' => '1.1000']]);

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->with('fx.circuit.ecb.failures', 0)->andReturn(0);
        $cache->shouldReceive('get')->with('fx.circuit.bundled.failures', 0)->andReturn(0);
        $cache->shouldReceive('forget')->with('fx.circuit.bundled.failures');
        $cache->shouldNotReceive('put');
        $cache->shouldNotReceive('increment');
        $cache->shouldReceive('add')->with('fx.circuit.ecb.failures', 1, Mockery::any())->once()->andReturn(true);

        $registry = new RateProviderRegistry([$ecb, $bundled], $cache);

        expect($registry->fetchCurrentRates()['provider'])->toBe('bundled');
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
