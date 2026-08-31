<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Enums\Currency as CurrencyCode;

// updateOrInsert keeps this idempotent so beatrax:install can safely
// re-run without duplicating currency rows.
final class CurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => CurrencyCode::Eur->value, 'name' => 'Euro', 'minor_unit' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'minor_unit' => 2],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'minor_unit' => 2],
            // The only zero-decimal code the app carries, and the reason it is
            // here: Currency, Money's symbol map and the ICS parser all already
            // speak JPY, so leaving it out of this table made every ÷100
            // assumption unreachable through the UI and therefore untested.
            ['code' => CurrencyCode::Jpy->value, 'name' => 'Japanese Yen', 'minor_unit' => 0],
        ] as $row) {
            Currency::query()->updateOrInsert(['code' => $row['code']], $row);
        }
    }
}
