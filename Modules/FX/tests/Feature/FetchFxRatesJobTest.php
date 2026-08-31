<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;
use Modules\FX\Internal\Providers\EcbRateProvider;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Services\FxRefreshStatus;
use Modules\FX\Public\Support\BundledRates;
use Psr\Log\LoggerInterface;

// The job re-checks fx_online_enabled as a privacy gate of its own, so every
// happy-path case has to seed a user who opted into online fetch.
beforeEach(function (): void {
    $this->fxUserId = User::create([
        'username' => 'fx-job-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
        'fx_online_enabled' => true,
    ])->id;
});

/**
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

        $cache = app(Repository::class);
        $ecbProvider = app(EcbRateProvider::class);
        $registry = new RateProviderRegistry([$ecbProvider], $cache);
        app()->instance(RateProviderRegistry::class, $registry);

        $job = new FetchFxRatesJob($this->fxUserId);
        $job->handle($registry, app(DatabaseManager::class), app(LoggerInterface::class), app(FxRefreshStatus::class));

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
        $status = app(FxRefreshStatus::class);
        $job = new FetchFxRatesJob($this->fxUserId);

        $job->handle($registry, $db, $logger, $status);
        $job->handle($registry, $db, $logger, $status);

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
        $status = app(FxRefreshStatus::class);
        $job = new FetchFxRatesJob($this->fxUserId);
        $job->handle($registry, $db, $logger, $status);

        expect(DB::table('exchange_rates')->where('rate_date', $feedDate)->exists())->toBeTrue();
        expect(DB::table('exchange_rates')->where('rate_date', now()->toDateString())->exists())->toBeFalse();
    });

    it('skips out-of-range rates while still persisting the valid ones', function (): void {
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

        $job = new FetchFxRatesJob($this->fxUserId);
        $job->handle($registry, $db, app(LoggerInterface::class), app(FxRefreshStatus::class));

        expect(DB::table('exchange_rates')->where('quote_currency', 'USD')->exists())->toBeTrue();
        expect(DB::table('exchange_rates')->where('quote_currency', 'XYZ')->exists())->toBeFalse();
    });

    it('no-ops without touching any provider when the user has online fetch disabled', function (): void {
        $disabledUser = User::create([
            'username' => 'fx-disabled-fixture',
            'password' => 'fixture-password-12chars',
            'period_start_day' => 1,
            'base_currency' => 'EUR',
            'fx_online_enabled' => false,
        ]);

        // A tripwire: the job must not reach the provider chain for an opted-out user.
        $tripwire = new class implements RateProvider
        {
            public function key(): string
            {
                return 'tripwire';
            }

            public function priority(): int
            {
                return 999;
            }

            public function fetch(): array
            {
                throw new RuntimeException('Provider must not be consulted for an opted-out user.');
            }
        };

        $registry = new RateProviderRegistry([$tripwire], app(Repository::class));

        $job = new FetchFxRatesJob($disabledUser->id);
        $job->handle($registry, app(DatabaseManager::class), app(LoggerInterface::class), app(FxRefreshStatus::class));

        // Not a bare count: the bundled snapshot is seeded at migrate time, so
        // what this asserts is that no provider wrote anything on top of it.
        expect(DB::table('exchange_rates')->where('source', '!=', BundledRates::SOURCE)->count())->toBe(0);
    });

    it('no-ops for a non-existent user id', function (): void {
        $registry = new RateProviderRegistry([], app(Repository::class));

        $job = new FetchFxRatesJob(999_999);
        $job->handle($registry, app(DatabaseManager::class), app(LoggerInterface::class), app(FxRefreshStatus::class));

        expect(DB::table('exchange_rates')->where('source', '!=', BundledRates::SOURCE)->count())->toBe(0);
    });
});
