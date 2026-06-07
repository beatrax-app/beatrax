<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Core\Public\Support\LockStore;
use Modules\FX\Internal\RateProviderRegistry;

/**
 * Fetches current exchange rates from the provider chain and writes
 * idempotent rows to `exchange_rates`.
 *
 * Concurrency contract (mirrors ProjectForecastJob):
 *  - ShouldBeUniqueUntilProcessing keyed on uniqueId() = "{userId}"
 *    collapses any concurrent (scheduled-tick + on-demand refresh)
 *    trigger pair into a single queued job per user.
 *  - tries = 3 + backoff = [60, 300, 900] keeps a transient provider
 *    failure from final-failing the run without two retries.
 *
 * Idempotency: rows are upserted on the unique index
 * (base_currency, quote_currency, rate_date, source) so re-running
 * the job never duplicates rows (CLAUDE.md idempotency rule).
 *
 * The date keyed in `exchange_rates` is the date from the provider's
 * feed response (the ECB `time` attribute), NOT `now()`. On weekends
 * and ECB public holidays the feed publishes the previous business
 * day's date — keying on now() would produce a false "today" row
 * (Pitfall 2).
 *
 * Rate values are validated against a plausible range (0.00001–100000)
 * before upsert. Values outside this range are logged and skipped
 * (T-02-01 / STRIDE Threat Register).
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

    public function handle(RateProviderRegistry $registry, DatabaseManager $db): void
    {
        $result = $registry->fetchCurrentRates();

        $date = $result['date'];

        /** @var array<string, string> $rates */
        $rates = $result['rates'];

        $source = $result['provider'];

        $rows = [];
        $now = now()->toDateTimeString();

        foreach ($rates as $quoteCurrency => $rateStr) {
            // Validate rate within plausible range (T-02-01)
            $rateFloat = (float) $rateStr;

            if ($rateFloat < self::RATE_MIN || $rateFloat > self::RATE_MAX) {
                Log::warning('FetchFxRatesJob: out-of-range rate skipped.', [
                    'currency' => $quoteCurrency,
                    'rate' => $rateStr,
                    'source' => $source,
                    'date' => $date,
                ]);

                continue;
            }

            $rows[] = [
                'base_currency' => 'EUR',
                'quote_currency' => (string) $quoteCurrency,
                'rate_date' => $date,             // provider's date, NOT now() — Pitfall 2
                'rate' => $rateStr,
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        // Upsert on the unique index — idempotent on re-run
        $db->connection()->table('exchange_rates')->upsert(
            $rows,
            ['base_currency', 'quote_currency', 'rate_date', 'source'],
            ['rate', 'updated_at'],
        );
    }
}
