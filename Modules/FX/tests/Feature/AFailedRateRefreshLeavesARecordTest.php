<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Internal\Exceptions\AllProvidersFailed;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Enums\FxRefreshFailureReason;
use Modules\FX\Public\Exceptions\RateFetchException;
use Modules\FX\Public\Services\FxRefreshStatus;
use Psr\Log\LoggerInterface;

function makeRefusingRateProvider(string $key): RateProvider
{
    return new class($key) implements RateProvider
    {
        public function __construct(private readonly string $k) {}

        public function key(): string
        {
            return $this->k;
        }

        public function priority(): int
        {
            return 100;
        }

        public function fetch(): array
        {
            throw new RateFetchException('offline');
        }
    };
}

/**
 * @param  array{date: string, rates: array<string, string>}  $result
 */
function makeAnsweringRateProvider(string $key, array $result): RateProvider
{
    return new class($key, $result) implements RateProvider
    {
        /** @param array{date: string, rates: array<string, string>} $result */
        public function __construct(private readonly string $k, private readonly array $result) {}

        public function key(): string
        {
            return $this->k;
        }

        public function priority(): int
        {
            return 100;
        }

        public function fetch(): array
        {
            return $this->result;
        }
    };
}

describe('a rate refresh that came back with nothing', function (): void {
    beforeEach(function (): void {
        $this->fxUserId = User::create([
            'username' => 'fx-refresh-record',
            'password' => 'fixture-password-12chars',
            'period_start_day' => 1,
            'base_currency' => 'EUR',
            'fx_online_enabled' => true,
        ])->id;

        $this->status = app(FxRefreshStatus::class);
        $this->runJob = function (RateProviderRegistry $registry): void {
            (new FetchFxRatesJob($this->fxUserId))->handle(
                $registry,
                app(DatabaseManager::class),
                app(LoggerInterface::class),
                app(FxRefreshStatus::class),
            );
        };
    });

    it('says every rate source refused, rather than leaving the reader to time out', function (): void {
        $registry = new RateProviderRegistry([makeRefusingRateProvider('ecb')], app(Repository::class));

        expect(fn () => ($this->runJob)($registry))->toThrow(AllProvidersFailed::class);

        expect($this->status->lastFailure($this->fxUserId)?->reason)
            ->toBe(FxRefreshFailureReason::AllProvidersFailed);
    });

    it('says so when a feed answered but every rate it carried was thrown away', function (): void {
        $registry = new RateProviderRegistry(
            [makeAnsweringRateProvider('ecb', ['date' => '2026-06-05', 'rates' => ['XYZ' => '999999999']])],
            app(Repository::class),
        );

        ($this->runJob)($registry);

        expect($this->status->lastFailure($this->fxUserId)?->reason)
            ->toBe(FxRefreshFailureReason::NoUsableRates);
    });

    it('is recorded once the job has exhausted its retries', function (): void {
        (new FetchFxRatesJob($this->fxUserId))->failed(new AllProvidersFailed('every provider failed'));

        expect($this->status->lastFailure($this->fxUserId)?->reason)
            ->toBe(FxRefreshFailureReason::AllProvidersFailed);
    });

    it('names an unexpected failure apart from an unreachable provider chain', function (): void {
        (new FetchFxRatesJob($this->fxUserId))->failed(new RuntimeException('database is locked'));

        expect($this->status->lastFailure($this->fxUserId)?->reason)
            ->toBe(FxRefreshFailureReason::Unexpected);
    });

    it('stops reporting the failure once a later refresh succeeds', function (): void {
        $this->status->recordFailure($this->fxUserId, FxRefreshFailureReason::AllProvidersFailed);

        $registry = new RateProviderRegistry(
            [makeAnsweringRateProvider('ecb', ['date' => '2026-06-05', 'rates' => ['USD' => '1.1359']])],
            app(Repository::class),
        );

        ($this->runJob)($registry);

        expect($this->status->lastFailure($this->fxUserId))->toBeNull();
    });

    it('stops reporting the failure once the reader turns online fetch off', function (): void {
        $this->status->recordFailure($this->fxUserId, FxRefreshFailureReason::AllProvidersFailed);

        User::query()->whereKey($this->fxUserId)->update(['fx_online_enabled' => false]);

        ($this->runJob)(new RateProviderRegistry([makeRefusingRateProvider('ecb')], app(Repository::class)));

        expect($this->status->lastFailure($this->fxUserId))->toBeNull();
    });

    it('does not show one reader the failure recorded against another', function (): void {
        $other = User::create([
            'username' => 'fx-refresh-other',
            'password' => 'fixture-password-12chars',
            'period_start_day' => 1,
            'base_currency' => 'EUR',
            'fx_online_enabled' => true,
        ])->id;

        $this->status->recordFailure($this->fxUserId, FxRefreshFailureReason::AllProvidersFailed);

        expect($this->status->lastFailure($other))->toBeNull();
    });
});
