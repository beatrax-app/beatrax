<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('exchange_rates', static function (Blueprint $table): void {
            $table->index(['rate_date'], 'exchange_rates_newest_date');
        });
    }

    public function down(): void
    {
        $this->schema()->table('exchange_rates', static function (Blueprint $table): void {
            $table->dropIndex('exchange_rates_newest_date');
        });
    }
};
