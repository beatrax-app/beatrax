<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('forecast_shortfall_windows', static function (Blueprint $table): void {
            // Five horizons project the same account and each one used to
            // delete the others' rows on its way in, so whichever run finished
            // last decided the shortfall band at every horizon.
            $table->unsignedSmallInteger('horizon_days')->default(0)->after('scenario_id');
            $table->index(['user_id', 'account_id', 'horizon_days', 'starts_at']);
        });

        // A row written before this column cannot say which horizon detected
        // it, and it is pure derived output — the next projection rewrites the
        // whole picture for the account it belongs to.
        $this->db()->connection($this->getConnection())
            ->table('forecast_shortfall_windows')
            ->where('horizon_days', 0)
            ->delete();
    }

    public function down(): void
    {
        $this->schema()->table('forecast_shortfall_windows', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'account_id', 'horizon_days', 'starts_at']);
            $table->dropColumn('horizon_days');
        });
    }
};
