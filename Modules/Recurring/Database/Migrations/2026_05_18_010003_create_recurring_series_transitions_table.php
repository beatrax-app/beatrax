<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Append-only by project-wide schema invariant rather than a per-table trigger,
// unlike recurring_series.state. transitioned_at is the canonical timestamp;
// created_at/updated_at are only Laravel's convention.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('recurring_series_transitions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            $table->string('transition_reason', 64);
            $table->string('actor', 16);
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['recurring_series_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('recurring_series_transitions');
    }
};
