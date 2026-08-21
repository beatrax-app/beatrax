<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `pinned` carries a DB-level default, which deliberately keeps it OUT of the
// Sync merge registry's create-required set even though the column is NOT NULL.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('saved_reports', static function (Blueprint $table): void {
            $table->id();
            // Nullable: unlike envelope_settings there is no UNIQUE here that
            // would need a non-null user_id for upsert safety.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('definition');
            $table->boolean('pinned')->default(false);
            $table->unsignedTinyInteger('pin_order')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('saved_reports');
    }
};
