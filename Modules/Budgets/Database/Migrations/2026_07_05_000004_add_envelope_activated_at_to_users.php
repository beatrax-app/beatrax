<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// NULL until the pot-archive cutover stamps it. CarryoverQuery bounds its
// past walk at this timestamp's period, so "genesis carried_in is 0" has a
// fixed anchor instead of drifting as assignment rows accumulate.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->timestamp('envelope_activated_at')
                ->nullable()
                ->after('anomaly_backfilled_at');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn(['envelope_activated_at']);
        });
    }
};
