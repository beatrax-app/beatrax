<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A migration rather than a seeder because only `beatrax:install` seeded this
// list, and a device that joins a household by pairing never runs it. An empty
// `currencies` table made `/settings` unsaveable: `base_currency` validates
// with `exists:currencies,code`, which gates the whole Money/period block.
return new class extends Migration
{
    /** @var list<array{code: string, name: string, minor_unit: int}> */
    private const CURRENCIES = [
        ['code' => 'EUR', 'name' => 'Euro', 'minor_unit' => 2],
        ['code' => 'USD', 'name' => 'US Dollar', 'minor_unit' => 2],
        ['code' => 'GBP', 'name' => 'Pound Sterling', 'minor_unit' => 2],
    ];

    // updateOrInsert, so a re-run also restores an edited row's canonical name
    // and minor_unit rather than leaving it as it found it.
    public function up(): void
    {
        foreach (self::CURRENCIES as $currency) {
            DB::table('currencies')->updateOrInsert(['code' => $currency['code']], $currency);
        }
    }

    public function down(): void
    {
        // Left in place: a transaction or account may reference a code, and
        // removing reference data on a rollback would break rows that are
        // still valid.
    }
};
