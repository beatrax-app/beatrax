<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Ledger\Public\Enums\Currency;

/** @link ../../../../.docs/features/chains/card-statement-lifecycle.md */
return new class extends ModuleMigration
{
    // Both tables stored an amount with nothing saying what it counted, so the
    // read site guessed EUR. Every row that exists came from the ICS reader,
    // whose header profile pins EUR, so the default backfills them correctly.
    public function up(): void
    {
        $this->schema()->table('card_statements', static function (Blueprint $table): void {
            $table->char('currency', 3)->default(Currency::Eur->value)->after('open_balance_minor');
        });

        $this->schema()->table('card_statement_credits', static function (Blueprint $table): void {
            $table->char('currency', 3)->default(Currency::Eur->value)->after('amount_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('card_statements', static function (Blueprint $table): void {
            $table->dropColumn('currency');
        });

        $this->schema()->table('card_statement_credits', static function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
