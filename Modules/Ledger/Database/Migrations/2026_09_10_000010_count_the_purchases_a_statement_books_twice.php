<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The dedup tuple read seven columns a bank fills identically for two identical
// purchases on one day, so the second was dropped and the reader's balance was
// short by a purchase they made. The ordinal is the eighth: which occurrence of
// that tuple the row is, counted within the file it arrived in.
return new class extends ModuleMigration
{
    private const string INDEX = 'transactions_fingerprint_uq';

    private const string COLUMN = 'occurrence_ordinal';

    public function up(): void
    {
        if (! $this->schema()->hasTable('transactions') || $this->schema()->hasColumn('transactions', self::COLUMN)) {
            return;
        }

        $this->schema()->table('transactions', static function (Blueprint $table): void {
            // Defaulted rather than required, so a create from a peer still on
            // the previous build inserts as the single occurrence it described
            // instead of failing its NOT NULL.
            $table->unsignedInteger(self::COLUMN)->default(0)->after('source_ref');
        });

        $this->numberTheOccurrencesAlreadyStored();

        // Widening the tuple can only accept rows the narrower one accepted, so
        // no row is merged away and the CREATE cannot fail on stored data.
        $this->connection()->statement('DROP INDEX IF EXISTS '.self::INDEX);
        $this->connection()->statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON transactions('
            .'user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized, '
            .self::COLUMN.')'
        );
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('transactions') || ! $this->schema()->hasColumn('transactions', self::COLUMN)) {
            return;
        }

        // Every row this column let in is a row the narrower index refuses, so
        // dropping it here would fail the CREATE rather than lose them quietly.
        $this->connection()->statement('DROP INDEX IF EXISTS '.self::INDEX);
        $this->connection()->statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON transactions('
            .'user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)'
        );

        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    // Position within the tuple group, oldest id first, rather than a flat
    // zero: SQLite counts NULLs as distinct in a UNIQUE index, so rows written
    // before user_id was always set can already sit two to a group.
    private function numberTheOccurrencesAlreadyStored(): void
    {
        $this->connection()->statement(
            'UPDATE transactions SET '.self::COLUMN.' = (SELECT COUNT(*) FROM transactions AS earlier'
            .' WHERE earlier.user_id IS transactions.user_id'
            .' AND earlier.account_id = transactions.account_id'
            .' AND earlier.posted_at = transactions.posted_at'
            .' AND earlier.booked_at = transactions.booked_at'
            .' AND earlier.amount_minor = transactions.amount_minor'
            .' AND earlier.currency = transactions.currency'
            .' AND earlier.counterparty_normalized = transactions.counterparty_normalized'
            .' AND earlier.id < transactions.id)'
        );
    }

    private function connection(): Connection
    {
        return $this->db()->connection($this->getConnection());
    }
};
