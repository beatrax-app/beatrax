<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('tax_deduction_categories', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('short_name', 32);
            $table->text('hint')->nullable();
            // Non-null (with country_code) on a built-in corpus category so the
            // corpus can be re-seeded idempotently; null on a user's own.
            $table->string('corpus_key', 64)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('tax_deduction_categories');
    }
};
