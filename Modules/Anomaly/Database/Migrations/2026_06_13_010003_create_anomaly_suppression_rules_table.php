<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('anomaly_suppression_rules', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->string('detector', 16);
            $table->enum('direction', ['expense', 'income']);
            $table->bigInteger('amount_band_low_minor');
            $table->bigInteger('amount_band_high_minor');
            $table->char('currency', 3);
            // Null on delete, not cascade: losing the alert that created the
            // rule must not silently un-mute the merchant.
            $table->foreignId('source_anomaly_alert_id')->nullable()->constrained('anomaly_alerts')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'counterparty_id', 'detector']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('anomaly_suppression_rules');
    }
};
