<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Currency;

// updateOrInsert keeps this idempotent so beatrax:install can safely
// re-run without duplicating currency rows.
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
