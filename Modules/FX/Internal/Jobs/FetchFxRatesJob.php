<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Support\LockStore;
use Modules\FX\Internal\Exceptions\AllProvidersFailed;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Enums\FxRefreshFailureReason;
use Modules\FX\Public\Services\FxRefreshStatus;
use Modules\Ledger\Public\Enums\Currency;
use Psr\Log\LoggerInterface;
use Throwable;

final class FetchFxRatesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    private const float RATE_MIN = 0.00001;

    private const float RATE_MAX = 100_000.0;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        RateProviderRegistry $registry,
        DatabaseManager $db,
        LoggerInterface $logger,
        FxRefreshStatus $status,
    ): void {
        // Re-checked here rather than trusted from the caller: the UI gate is
        // not a security boundary, and no dispatch path may make a network
        // call for a user who never opted in.
        $fxOnlineEnabled = $db->connection()->table('users')
            ->where('id', $this->userId)
            ->value('fx_online_enabled');

        if (! (bool) $fxOnlineEnabled) {
            $logger->info('FetchFxRatesJob: skipped — user has online fetch disabled or does not exist.', [
                'user_id' => $this->userId,
            ]);

            $status->clear($this->userId);

            return;
        }

        // Recorded on this attempt rather than only from failed(), because the
        // settings screen waits seconds while the retry backoff runs to twenty
        // minutes: by the time the last attempt gives up, the reader has been
        // watching a spinner die with no reason for it.
        try {
            $result = $registry->fetchCurrentRates();
        } catch (AllProvidersFailed $exhausted) {
            $status->recordFailure($this->userId, FxRefreshFailureReason::AllProvidersFailed);

            throw $exhausted;
        }

        $date = $result['date'];

        /** @var array<string, string> $rates */
        $rates = $result['rates'];

        $source = $result['provider'];

        $rows = [];
        $now = CarbonImmutable::now()->toDateTimeString();

        foreach ($rates as $quoteCurrency => $rateStr) {
            $rateFloat = (float) $rateStr;

            if ($rateFloat < self::RATE_MIN || $rateFloat > self::RATE_MAX) {
                $logger->warning('FetchFxRatesJob: out-of-range rate skipped.', [
                    'currency' => $quoteCurrency,
                    'rate' => $rateStr,
                    'source' => $source,
                    'date' => $date,
                ]);

                continue;
            }

            // The provider's feed date, never now(): on a weekend or holiday
            // the feed still carries the previous business day, and now()
            // would write a false "today" row.
            $rows[] = [
                'base_currency' => Currency::Eur->value,
                'quote_currency' => $quoteCurrency,
                'rate_date' => $date,
                'rate' => $rateStr,
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            // A feed that answered and left nothing behind writes no row, so the
            // screen watching for one waits on a write that is never coming.
            $status->recordFailure($this->userId, FxRefreshFailureReason::NoUsableRates);

            return;
        }

        $db->connection()->table('exchange_rates')->upsert(
            $rows,
            ['base_currency', 'quote_currency', 'rate_date', 'source'],
            ['rate', 'updated_at'],
        );

        $status->clear($this->userId);
    }

    // Laravel calls this as a bare `$command->failed($e)` with no container
    // resolution, so collaborators cannot be declared as parameters.
    public function failed(?Throwable $exception): void
    {
        $container = Container::getInstance();

        $container->make(FxRefreshStatus::class)->recordFailure(
            $this->userId,
            $exception instanceof AllProvidersFailed
                ? FxRefreshFailureReason::AllProvidersFailed
                : FxRefreshFailureReason::Unexpected,
        );

        $container->make(LoggerInterface::class)->warning('FetchFxRatesJob: giving up on the rate refresh.', [
            'user_id' => $this->userId,
            'exception' => $exception === null ? null : $exception::class,
            'message' => $exception?->getMessage(),
        ]);
    }
}
