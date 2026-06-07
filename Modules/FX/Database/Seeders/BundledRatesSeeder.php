<?php

declare(strict_types=1);

namespace Modules\FX\Database\Seeders;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;

/**
 * Seeds the bundled offline exchange-rate snapshot into `exchange_rates`.
 *
 * Reads `Modules/FX/Resources/rates-snapshot.json` and upserts one row
 * per currency pair on the unique index
 * (base_currency, quote_currency, rate_date, source) so the seeder is
 * fully idempotent — safe to re-run on every `beatrax:install` invocation
 * (CLAUDE.md idempotency rule).
 *
 * No Eloquent model exists for `exchange_rates`; rows are written via
 * raw query builder (DatabaseManager) to avoid coupling the seeder to an
 * Eloquent model that would only be used in this one place (01-PATTERNS.md
 * BundledRatesSeeder pattern).
 *
 * Source 'bundled' distinguishes these rows from live ECB or Frankfurter
 * rows so callers can surface the appropriate transparency label in the
 * FX disclosure affordance (D-11/D-12).
 */
final class BundledRatesSeeder extends Seeder
{
    private string $snapshotPath;

    public function __construct(
        private readonly DatabaseManager $db,
        ?string $snapshotPath = null,
    ) {
        $this->snapshotPath = $snapshotPath
            ?? __DIR__.'/../../Resources/rates-snapshot.json';
    }

    public function run(): void
    {
        $raw = file_get_contents($this->snapshotPath);

        if ($raw === false) {
            $this->command?->error('BundledRatesSeeder: snapshot file not found at '.$this->snapshotPath);

            return;
        }

        /** @var array{date: string, rates: array<string, string>}|null $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['date'], $data['rates'])) {
            $this->command?->error('BundledRatesSeeder: invalid snapshot format.');

            return;
        }

        $date = $data['date'];
        $rates = $data['rates'];
        $now = now()->toDateTimeString();

        $rows = [];

        foreach ($rates as $quoteCurrency => $rate) {
            $rows[] = [
                'base_currency' => 'EUR',
                'quote_currency' => (string) $quoteCurrency,
                'rate_date' => $date,
                'rate' => (string) $rate,
                'source' => 'bundled',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        // Upsert on the unique index — idempotent on re-run
        $this->db->connection()->table('exchange_rates')->upsert(
            $rows,
            ['base_currency', 'quote_currency', 'rate_date', 'source'],
            ['rate', 'updated_at'],
        );
    }
}
