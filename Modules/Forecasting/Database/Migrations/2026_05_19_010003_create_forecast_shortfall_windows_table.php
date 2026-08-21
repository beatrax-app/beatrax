<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_shortfall_windows', static function (Blueprint $table): void {
            $table->id();
            // Non-nullable: a NULL user_id would silently escape per-user filters.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            // NULL scenario_id = the baseline projection's shortfall.
            $table->foreignId('scenario_id')->nullable()->constrained('forecast_scenarios')->cascadeOnDelete();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->bigInteger('lowest_balance_minor');
            $table->string('currency', 3);
            // Frozen at detection time so a later buffer edit cannot rewrite the
            // shortfall history this row is the audit of.
            $table->bigInteger('buffer_used_minor');
            $table->timestamps();

            $table->index(['user_id', 'account_id', 'starts_at']);
            $table->index(['user_id', 'scenario_id']);
            $table->index(['user_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_shortfall_windows');
    }
};
