<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// No production writer ever set it, and the projection that read it threw on
// the null it always found -- one dollar subscription took the whole forecast
// run down. The rate is resolved live at the fold now, so the column is not
// dormant, it is answered elsewhere.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropColumn('latest_fx_rate_used');
        });
    }

    public function down(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->string('latest_fx_rate_used')->nullable();
        });
    }
};
