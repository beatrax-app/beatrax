<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The currency list is reference data every install needs, but the only thing
 * that seeded it was `beatrax:install` — and a device that joins a household
 * by PAIRING never runs that command. Its `currencies` table stayed empty.
 *
 * The cost was not a missing dropdown. `/settings` validates
 * `base_currency` with `exists:currencies,code`, and the empty select syncs an
 * empty string back through wire:model, so save() failed validation every
 * time. That one validator gates the whole Money/period block — period start
 * day, currency view, the recurring window, the drift threshold — so none of
 * it could be changed on a paired phone. The only feedback was a red
 * "Kies een valuta." 863px above the Save button in an 891px viewport, asking
 * the user to choose from a select with no options.
 *
 * A migration rather than a seeder: it runs wherever the schema does, however
 * the device was set up. Idempotent, so re-running is safe — updateOrInsert,
 * so a re-run also restores the canonical name and minor_unit rather than
 * leaving an edited row in place.
 *
 * @link ../../../../.docs/features/ledger/architecture.md
 */
return new class extends Migration
{
    /** @var list<array{code: string, name: string, minor_unit: int}> */
    private const CURRENCIES = [
        ['code' => 'EUR', 'name' => 'Euro', 'minor_unit' => 2],
        ['code' => 'USD', 'name' => 'US Dollar', 'minor_unit' => 2],
        ['code' => 'GBP', 'name' => 'Pound Sterling', 'minor_unit' => 2],
    ];

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
