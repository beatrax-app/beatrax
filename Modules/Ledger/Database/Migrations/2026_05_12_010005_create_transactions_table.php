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

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // Cross-currency rows record both native and settled pairs, so FX
            // information is never lost on import.
            $table->bigInteger('settled_amount_minor');
            $table->char('settled_currency', 3);
            $table->decimal('fx_rate_used', 18, 8)->nullable();

            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban', 34)->nullable();
            $table->string('counterparty_normalized', 80);
            $table->unsignedSmallInteger('normalization_version');

            $table->text('description')->nullable();

            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('source_format', 32);
            $table->foreignId('import_run_id')->constrained('import_runs');
            $table->unsignedInteger('source_row_index');
            $table->string('source_ref')->nullable();

            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('fingerprint_version');

            $table->string('status', 16)->default('cleared');
            $table->timestamps();

            $table->index(['user_id', 'posted_at']);
            $table->index(['account_id', 'posted_at']);
            $table->index(['category_id', 'posted_at']);
        });

        // Partial index for the uncategorized triage inbox.
        DB::statement('CREATE INDEX transactions_uncategorized_idx ON transactions(user_id, posted_at) WHERE category_id IS NULL');

        // Composite UNIQUE — DB-layer fingerprint enforcement. user_id is the
        // leading column so the same row imported under two distinct users is
        // accepted as two distinct ledger entries.
        DB::statement('CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions(user_id, account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)');

        // The SHA-256 tuple already begins with user_id (FingerprintComposer::compose),
        // so leading with user_id here buys a covered lookup while still
        // preventing cross-user fingerprint collisions.
        DB::statement('CREATE UNIQUE INDEX transactions_fingerprint_sha_uq ON transactions(user_id, fingerprint)');

        // SQLite cannot ALTER TABLE ADD CHECK, so the allowed type set is enforced by
        // triggers, which fire on every write path — Eloquent and raw insertOrIgnore
        // alike. Must stay in sync with Modules\Ledger\Public\Enums\TransactionType.
        $allowedTypes = "'expense','income','transfer_out','transfer_in','fee','refund','adjustment'";
        DB::statement(sprintf(
            "CREATE TRIGGER transactions_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
        DB::statement(sprintf(
            "CREATE TRIGGER transactions_type_check_update BEFORE UPDATE OF type ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
