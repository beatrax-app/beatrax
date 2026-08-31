<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The flat per-category ceiling the envelope cutover replaced. It has been
// write-dead since 2026-07-05 and unread since the position summary moved onto
// the envelope fold, so the table is the last copy of a retired model.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->dropIfExists('category_budgets');
    }

    public function down(): void
    {
        $this->schema()->create('category_budgets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('period_type', 16)->default('monthly');
            $table->bigInteger('budget_minor');
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['user_id', 'category_id'], 'category_budgets_user_category_uniq');
        });
    }
};
