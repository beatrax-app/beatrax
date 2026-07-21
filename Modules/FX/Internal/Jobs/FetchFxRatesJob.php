<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Public\Support\LockStore;
use Modules\FX\Internal\RateProviderRegistry;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/fx/architecture.md
 */
final class FetchFxRatesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const float RATE_MIN = 0.00001;

    private const float RATE_MAX = 100_000.0;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

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

    public function handle(RateProviderRegistry $registry, DatabaseManager $db, LoggerInterface $logger): void
    {
        // Defense-in-depth privacy gate: online fetch is opt-in and off by
        // default. Re-checking here (rather than trusting the caller) means
        // no dispatch path can leak network calls for a user who never
        // enabled it — the UI gate alone is not a security boundary.
        $fxOnlineEnabled = $db->connection()->table('users')
            ->where('id', $this->userId)
            ->value('fx_online_enabled');

        if (! (bool) $fxOnlineEnabled) {
            $logger->info('FetchFxRatesJob: skipped — user has online fetch disabled or does not exist.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $result = $registry->fetchCurrentRates();

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

            // rate_date is the provider's own feed date, never now() — on a
            // weekend or ECB holiday the feed still publishes the previous
            // business day's date, and keying on now() would write a false
            // "today" row.
            $rows[] = [
                'base_currency' => 'EUR',
                'quote_currency' => $quoteCurrency,
                'rate_date' => $date,
                'rate' => $rateStr,
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        $db->connection()->table('exchange_rates')->upsert(
            $rows,
            ['base_currency', 'quote_currency', 'rate_date', 'source'],
            ['rate', 'updated_at'],
        );
    }
}
