<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the envelope_moves table — an append-only, paired-row ledger for
 * envelope-to-envelope moves (D-02). Mirrors pot_movements' shape: a move
 * writes a debit row on the source envelope and a credit row on the target
 * envelope in one DB transaction, keyed by `period_start`. Each envelope's
 * `net_moved(cat, month) = SUM(amount_minor)` over its own rows.
 *
 * `envelope_moves` is entirely separate from the real `transactions` table
 * and must never appear in forecasts, cash-flow surfaces, or categorization —
 * it represents virtual allocations, not real money flows (mirrors pot
 * movements' D-06 discipline).
 *
 * `amount_minor` is SIGNED BIGINT: positive = into envelope, negative = out
 * of envelope. Never use a float type for money (NoFloatMoneyArchTest
 * enforces this).
 *
 * `user_id` is NULLABLE, denormalized — mirrors pot_movements/
 * transaction_splits' append-only convention (RESEARCH.md Pitfall 4 / A3),
 * the opposite fork from envelope_assignments/envelope_settings. Register
 * this table (only) in UserIdColumnArchTest's domain-tables list.
 *
 * `category_id` and `counterpart_category_id` are BOTH NOT NULL with
 * cascadeOnDelete: D-02 says every move has a real counterpart category (a
 * move is always envelope-to-envelope), and the Sync
 * EnvelopeMovesRegistryColumnsTest pins `counterpart_category_id` as a
 * genuinely required create column — nullable+nullOnDelete would both
 * violate "every move has a real counterpart" and drop the column out of
 * the NOT-NULL-without-default set that test's `_create_required` list
 * requires. cascadeOnDelete (not nullOnDelete) on the counterpart keeps a
 * move's paired rows deleted together when either side's category is
 * removed, instead of leaving an orphaned half with an impossible-to-store
 * NULL counterpart.
 *
 * `currency` is NOT NULL with no DB-level default for the same
 * `_create_required` reason as envelope_assignments (v1 is EUR-only, D-25;
 * EnvelopeWriter always supplies it explicitly).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('envelope_moves', static function (Blueprint $table): void {
            $table->id();
            // NULLABLE per project append-only convention — see class doc.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            // NOT NULL — see class doc: every move has a real counterpart
            // category; cascadeOnDelete keeps paired rows deleted together.
            $table->foreignId('counterpart_category_id')->constrained('categories')->cascadeOnDelete();
            $table->date('period_start'); // D-04 month key
            // SIGNED: + = into envelope, − = out of envelope. BIGINT only — NO float.
            $table->bigInteger('amount_minor');
            // NOT NULL, no DB-level default — see class doc.
            $table->string('currency', 3);
            $table->string('kind', 32); // 'move_in' | 'move_out'
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'category_id', 'period_start']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('envelope_moves');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
