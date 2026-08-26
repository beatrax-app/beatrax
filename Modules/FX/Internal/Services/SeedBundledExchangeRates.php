<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\FX\Internal\Providers\BundledSnapshotProvider;
use Modules\FX\Public\Exceptions\RateFetchException;
use Modules\Ledger\Public\Enums\Currency;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/fx/bundled-rates.md
 */
final readonly class SeedBundledExchangeRates
{
    public function __construct(
        private DatabaseManager $db,
        private BundledSnapshotProvider $snapshot,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        try {
            $result = $this->snapshot->fetch();
        } catch (RateFetchException $e) {
            // A broken snapshot must not fail the migration that carries it:
            // the app works without rates, it just cannot convert.
            $this->logger->warning('SeedBundledExchangeRates: bundled snapshot unreadable.', [
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $now = $this->clock->now()->toDateTimeString();
        $rows = [];

        foreach ($result['rates'] as $quoteCurrency => $rate) {
            if ($quoteCurrency === Currency::Eur->value) {
                continue;
            }

            $rows[] = [
                'base_currency' => Currency::Eur->value,
                'quote_currency' => $quoteCurrency,
                'rate_date' => $result['date'],
                'rate' => $rate,
                'source' => $this->snapshot->key(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        // Keyed on source too, so a row a live provider already wrote for the
        // same day is a different row and is left exactly as it is.
        $this->db->connection()->table('exchange_rates')->upsert(
            $rows,
            ['base_currency', 'quote_currency', 'rate_date', 'source'],
            ['rate', 'updated_at'],
        );
    }
}
