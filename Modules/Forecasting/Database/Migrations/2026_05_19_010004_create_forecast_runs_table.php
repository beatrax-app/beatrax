<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // NULL scenario_id = baseline projection run.
            $table->foreignId('scenario_id')->nullable()->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->unsignedSmallInteger('horizon_days');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            // The 2s projection-status poll selects on exactly this tuple.
            $table->index(['user_id', 'scenario_id', 'horizon_days', 'status']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_runs');
    }
};
