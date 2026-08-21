<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('forecast_scenarios', static function (Blueprint $table): void {
            $table->id();
            // Non-nullable: a NULL user_id would escape every `where('user_id', ...)`
            // filter, and SQLite counts NULLs as distinct inside UNIQUE(user_id, name).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('forecast_scenarios');
    }
};
