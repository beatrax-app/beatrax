<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('type', 32);
            $table->date('posted_at');
            $table->dateTime('booked_at');
            $table->date('value_date');

            // Native currency (FND-04 + FND-07):
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // Settled currency (MC-01):
            $table->bigInteger('settled_amount_minor');
            $table->char('settled_currency', 3);
            $table->decimal('fx_rate_used', 18, 8)->nullable();

            // Counterparty:
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban', 34)->nullable();
            $table->string('counterparty_normalized', 80);
            $table->unsignedSmallInteger('normalization_version');

            // Description:
            $table->text('description')->nullable();

            // Category (CAT-01):
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Source provenance (ING-08):
            $table->string('source_format', 32);
            $table->foreignId('import_run_id')->constrained('import_runs');
            $table->unsignedInteger('source_row_index');
            $table->string('source_ref')->nullable();

            // Fingerprint (ING-06):
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('fingerprint_version');

            // Lifecycle:
            $table->string('status', 16)->default('cleared');
            $table->timestamps();

            // Period-window query indexes
            $table->index(['user_id', 'posted_at']);
            $table->index(['account_id', 'posted_at']);
            $table->index(['category_id', 'posted_at']);
        });

        // Partial index for the uncategorized triage inbox (CAT-05).
        DB::statement('CREATE INDEX transactions_uncategorized_idx ON transactions(user_id, posted_at) WHERE category_id IS NULL');

        // Composite UNIQUE — DB-layer fingerprint enforcement (ING-06 / D-16).
        DB::statement('CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions(account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)');

        // Second-layer SHA-256 fingerprint UNIQUE (defense in depth — A9).
        DB::statement('CREATE UNIQUE INDEX transactions_fingerprint_sha_uq ON transactions(fingerprint)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
