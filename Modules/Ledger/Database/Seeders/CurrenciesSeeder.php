<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Support\CurrencyNames;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;

// Every currency the bundled rate snapshot can price, because a reporting
// currency no rate can reach is one every roll-up would have to leave out.
// updateOrInsert keeps this idempotent so beatrax:install can safely re-run.
final class CurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CurrencyNames::codes() as $code) {
            Currency::query()->updateOrInsert(['code' => $code], ['minor_unit' => CurrencyScale::decimals($code)]);
        }
    }
}
