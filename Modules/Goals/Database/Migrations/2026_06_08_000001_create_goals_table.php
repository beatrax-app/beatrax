<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('goals', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('name');
            // Positivity is enforced only by GoalWriter::parseAmount; there is no
            // DB-level CHECK, so a seeder or any second write path has to
            // re-assert it.
            $table->bigInteger('target_minor');
            // Snapshotted at creation, so the goal keeps its denomination when
            // the user later switches base currency.
            $table->string('target_currency', 3)->default('EUR');
            $table->date('start_date');
            $table->date('target_date');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('goals');
    }
};
