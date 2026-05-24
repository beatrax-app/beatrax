<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Currency;

/**
 * Seeds the ISO 4217 currencies the app understands out of the box. Idempotent
 * via updateOrInsert so `beatrax:install` can run safely on every invocation.
 */
final class CurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'EUR', 'name' => 'Euro', 'minor_unit' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'minor_unit' => 2],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'minor_unit' => 2],
        ] as $row) {
            Currency::query()->updateOrInsert(['code' => $row['code']], $row);
        }
    }
}
