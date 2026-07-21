<?php

declare(strict_types=1);

namespace Modules\FX\Database\Seeders;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;

/**
 * @link ../../../../.docs/features/fx/architecture.md
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

        $this->db->connection()->table('exchange_rates')->upsert(
            $rows,
            ['base_currency', 'quote_currency', 'rate_date', 'source'],
            ['rate', 'updated_at'],
        );
    }
}
