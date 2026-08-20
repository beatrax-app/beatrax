<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('migration_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // 'ynab4' | 'nynab' | 'actual'
            $table->string('source_product');
            // 'parsed' | 'confirmed' | 'needs_attention' | 'discarded'
            $table->string('status')->default('parsed');
            $table->string('original_filename');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_runs');
    }
};
