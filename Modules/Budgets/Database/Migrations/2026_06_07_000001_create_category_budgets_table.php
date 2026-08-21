<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('category_budgets', static function (Blueprint $table): void {
            $table->id();
            // NOT NULL: NULL is distinct in a unique index, so a nullable
            // user_id would leave the UNIQUE upsert below unenforceable.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('period_type', 16)->default('monthly');
            $table->bigInteger('budget_minor'); // always positive: a ceiling, not a signed flow
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['user_id', 'category_id'], 'category_budgets_user_category_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('category_budgets');
    }
};
