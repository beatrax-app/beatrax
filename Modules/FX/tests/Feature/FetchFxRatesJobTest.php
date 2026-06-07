<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;
use Modules\FX\Internal\Providers\EcbRateProvider;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Contracts\RateProvider;
use Psr\Log\LoggerInterface;

/**
 * Creates a fake RateProvider that always returns the given result.
 *
 * @param  array{date: string, rates: array<string, string>}  $rates
 */
function makeFakeRateProvider(string $key, int $priority, array $rates): RateProvider
{
    return new class($key, $priority, $rates) implements RateProvider
    {
        public function __construct(
            private readonly string $k,
            private readonly int $p,
            /** @var array{date: string, rates: array<string, string>} */
            private readonly array $result,
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
            return $this->result;
        }
    };
}

describe('FetchFxRatesJob', function (): void {
    it('writes exchange_rates rows keyed on the feed date, not today', function (): void {
        $feedDate = '2026-06-05'; // a past date (not today)

        Http::fake([
            'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>
                <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                                 xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
                  <Cube>
                    <Cube time="'.$feedDate.'">
                      <Cube currency="USD" rate="1.1359"/>
                      <Cube currency="GBP" rate="0.83895"/>
                    </Cube>
                  </Cube>
                </gesmes:Envelope>',
                200
            ),
        ]);

        // Bind a registry that uses the real ECB provider so we test the full stack
        $cache = app(Repository::class);
        $ecbProvider = app(EcbRateProvider::class);
        $registry = new RateProviderRegistry([$ecbProvider], $cache);
        app()->instance(RateProviderRegistry::class, $registry);

        $job = new FetchFxRatesJob(1);
        $job->handle($registry, app(DatabaseManager::class), app(LoggerInterface::class));

        expect(DB::table('exchange_rates')->where('rate_date', $feedDate)->count())
            ->toBeGreaterThanOrEqual(2);

        expect(DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate_date' => $feedDate,
            'source' => 'ecb',
        ])->exists())->toBeTrue();
    });

    it('is idempotent — re-running the job does not duplicate rows', function (): void {
        $feedDate = '2026-06-05';

        Http::fake([
            'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>
                <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                                 xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
                  <Cube>
                    <Cube time="'.$feedDate.'">
                      <Cube currency="USD" rate="1.1359"/>
                    </Cube>
                  </Cube>
                </gesmes:Envelope>',
                200
            ),
        ]);

        $cache = app(Repository::class);
        $ecbProvider = app(EcbRateProvider::class);
        $registry = new RateProviderRegistry([$ecbProvider], $cache);

        $db = app(DatabaseManager::class);
        $logger = app(LoggerInterface::class);
        $job = new FetchFxRatesJob(1);

        // Run twice
        $job->handle($registry, $db, $logger);
        $job->handle($registry, $db, $logger);

        $count = DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate_date' => $feedDate,
            'source' => 'ecb',
        ])->count();

        expect($count)->toBe(1);
    });

    it('rows use the feed date, not today', function (): void {
        $feedDate = '2026-01-05'; // clearly in the past

        $fakeProvider = makeFakeRateProvider('ecb', 200, [
            'date' => $feedDate,
            'rates' => ['USD' => '1.1000'],
        ]);

        $cache = app(Repository::class);
        $registry = new RateProviderRegistry([$fakeProvider], $cache);

        $db = app(DatabaseManager::class);
        $logger = app(LoggerInterface::class);
        $job = new FetchFxRatesJob(1);
        $job->handle($registry, $db, $logger);

        // Verify row has feed date, not today
        expect(DB::table('exchange_rates')->where('rate_date', $feedDate)->exists())->toBeTrue();
        expect(DB::table('exchange_rates')->where('rate_date', now()->toDateString())->exists())->toBeFalse();
    });

    it('skips out-of-range rates (T-02-01 validation)', function (): void {
        $fakeProvider = makeFakeRateProvider('ecb', 200, [
            'date' => '2026-06-05',
            'rates' => [
                'USD' => '1.1359',     // valid
                'XYZ' => '999999999',   // out of range (> 100000)
            ],
        ]);

        $cache = app(Repository::class);
        $registry = new RateProviderRegistry([$fakeProvider], $cache);
        $db = app(DatabaseManager::class);

        $job = new FetchFxRatesJob(1);
        $job->handle($registry, $db, app(LoggerInterface::class));

        expect(DB::table('exchange_rates')->where('quote_currency', 'USD')->exists())->toBeTrue();
        expect(DB::table('exchange_rates')->where('quote_currency', 'XYZ')->exists())->toBeFalse();
    });
});
