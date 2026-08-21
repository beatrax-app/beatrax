<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Nullable because the baseline is auto-detected from the earliest imported
// statement and may not exist yet. Distinct from the accounts.opening_balance_*
// pair Forecasting owns for its manual fallback; both live on the same row.
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
