<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the tax_deduction_categories table.
 *
 * One row per deduction category, scoped to a user. Built-in corpus
 * categories carry a non-null `corpus_key` and country_code so they can
 * be re-seeded idempotently. User-created categories have corpus_key = null.
 *
 * `user_id` is NULLABLE per the project multi-user-ready convention.
 * The unique(user_id, name) constraint enforces no duplicate category names
 * per user (NULL user_id treated as system-level in the corpus context).
 *
 * The index(user_id, status) supports the common query pattern of loading
 * a user's active deduction categories.
 */
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
