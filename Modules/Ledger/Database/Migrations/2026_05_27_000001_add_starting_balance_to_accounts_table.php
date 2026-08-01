<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds two nullable columns to Ledger's accounts table for the auto-detected
 * baseline used by the first-run wizard's starting-balance setup.
 *
 *   - starting_balance_minor — nullable BIGINT; minor-unit baseline carried
 *     forward from the earliest known opening balance for the account.
 *   - starting_balance_date  — nullable DATE; the ISO date the baseline
 *     applies to (typically the period_start of the earliest imported
 *     statement summary).
 *
 * These columns are distinct from the existing accounts.opening_balance_*
 * columns owned by Forecasting (which carry a user-input manual fallback
 * for PayPal-style accounts). Both pairs coexist on the same row.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->bigInteger('starting_balance_minor')
                ->nullable()
                ->after('default_currency');
            $table->date('starting_balance_date')
                ->nullable()
                ->after('starting_balance_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->dropColumn([
                'starting_balance_minor',
                'starting_balance_date',
            ]);
        });
    }
};
