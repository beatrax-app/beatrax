<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('statement_summaries', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('iban_owner', 34);
            $table->string('statement_number', 64)->nullable();

            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();

            $table->bigInteger('opening_balance_minor')->nullable();
            $table->char('opening_balance_currency', 3)->nullable();
            $table->dateTime('opening_balance_date')->nullable();

            $table->bigInteger('closing_balance_minor')->nullable();
            $table->char('closing_balance_currency', 3)->nullable();
            $table->dateTime('closing_balance_date')->nullable();

            $table->unsignedInteger('entry_count')->default(0);

            $table->json('extras')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'import_run_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('statement_summaries');
    }
};
