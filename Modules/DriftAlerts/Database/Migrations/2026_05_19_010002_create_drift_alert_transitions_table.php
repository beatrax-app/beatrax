<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Append-only. Unlike drift_alerts there is no SQL trigger here; the
// append-only rule is a project-wide schema invariant.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('drift_alert_transitions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('drift_alert_id')->constrained('drift_alerts')->cascadeOnDelete();
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            $table->string('transition_reason', 64);
            $table->string('actor', 16);
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['drift_alert_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('drift_alert_transitions');
    }
};
