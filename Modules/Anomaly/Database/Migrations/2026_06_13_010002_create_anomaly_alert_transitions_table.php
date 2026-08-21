<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('anomaly_alert_transitions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('anomaly_alert_id')->constrained('anomaly_alerts')->cascadeOnDelete();
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('transition_reason', 64);
            $table->string('actor', 16);
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['anomaly_alert_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('anomaly_alert_transitions');
    }
};
