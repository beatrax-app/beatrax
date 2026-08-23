<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `accounts` is Ledger's table, but these three columns are Forecasting-owned:
// the buffer editors and BalanceAnchorResolver's card path own the writes, and
// Ledger reads the opening-balance pair only as a baseline. That is why the
// migration lives here rather than under Ledger.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            // NULL buffer = no buffer = the zero-crossing default.
            $table->bigInteger('forecast_min_buffer_minor')
                ->nullable()
                ->after('default_currency');
            // User-input anchor for PayPal / CSV-only sources that never deliver a
            // settled statement summary.
            $table->bigInteger('opening_balance_minor')
                ->nullable()
                ->after('forecast_min_buffer_minor');
            $table->date('opening_balance_as_of_date')
                ->nullable()
                ->after('opening_balance_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->dropColumn([
                'forecast_min_buffer_minor',
                'opening_balance_minor',
                'opening_balance_as_of_date',
            ]);
        });
    }
};
