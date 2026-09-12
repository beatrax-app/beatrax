<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Ledger\Public\Support\CurrencyNames;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;

// The table offered four codes while the bundled rate snapshot priced thirty, so
// a Swedish reader could not report in the krona and an imported krona account
// had no option to name itself with. A migration rather than a seeder because a
// device that joins a household by pairing never runs the install command.
/**
 * @link ../../../../.docs/features/ledger/currency-names.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();

        if (! $schema->hasTable('currencies')) {
            return;
        }

        // The English word this column held was read straight onto the screen,
        // which is why a Dutch reader was offered "Pound Sterling". The wording
        // now comes from the ICU transcript, per reader, and a words column no
        // screen reads is a second answer waiting to be preferred to the first.
        if ($schema->hasColumn('currencies', 'name')) {
            $schema->table('currencies', static function (Blueprint $table): void {
                $table->dropColumn('name');
            });
        }

        $connection = $this->db()->connection($this->getConnection());

        // The scale is the app's own, not ICU's: ICU 78 dropped the forint and
        // the rupiah to zero decimals where ICU 77 and ISO 4217 give them two,
        // and what a stored integer means cannot move with a host's libraries.
        foreach (CurrencyNames::codes() as $code) {
            // A fresh builder per row: updateOrInsert leaves its where on the
            // one it was called on, so a reused builder asks whether a row
            // matching every code so far exists and inserts a duplicate.
            $connection->table('currencies')
                ->updateOrInsert(['code' => $code], ['minor_unit' => CurrencyScale::decimals($code)]);
        }
    }

    public function down(): void
    {
        // Left in place, like the currency seeds before it: an account or a
        // transaction may already reference one of these codes, and the column
        // held a word nothing reads.
    }
};
