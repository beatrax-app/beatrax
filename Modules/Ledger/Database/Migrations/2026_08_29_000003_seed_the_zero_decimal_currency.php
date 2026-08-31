<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// JPY was in the Currency enum, in Money's symbol map and in the ICS parser's
// accepted codes, but never in `currencies` — the table `base_currency` and
// SetAccountCurrency both validate against. So the one denomination whose minor
// unit is not 1/100 could not be picked, and every ÷100 assumption in the app
// was unreachable through the UI.
/**
 * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md
 */
return new class extends ModuleMigration
{
    private const CODE = 'JPY';

    public function up(): void
    {
        $this->db()->connection($this->getConnection())->table('currencies')->updateOrInsert(
            ['code' => self::CODE],
            ['code' => self::CODE, 'name' => 'Japanese Yen', 'minor_unit' => 0],
        );
    }

    public function down(): void
    {
        // Left in place, like the sibling currency seed migration: an account
        // or transaction may already reference the code.
    }
};
