<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the counterparties table — one row per (user_id, slug)
 * materialises one entity the user transacts with (merchant, personal
 * P2P, bank, government, or unresolved unknown).
 *
 * Schema:
 *
 *   - id, user_id (FK to users with cascade delete) — per-user scope.
 *   - type (16) — one of merchant|personal|bank|government|
 *     self_account|unknown. The allowed set is enforced via paired
 *     BEFORE INSERT / BEFORE UPDATE OF type triggers; an application-
 *     layer typo fails loud at the database boundary rather than
 *     landing a silently-broken row.
 *   - slug (128) — URL-routing key, per-user-unique. For personal
 *     counterparties the slug is the kebab-cased display name only;
 *     the IBAN never leaks into this column.
 *   - display_name — the canonical name rendered in lists, profile
 *     pages, and transaction rows.
 *   - iban (64, nullable) — preserved when the source transaction
 *     carried one. For personal rows this column is populated but
 *     never echoed into the slug or any URL.
 *   - merchant_name (nullable) — set for type=merchant rows so the
 *     Categorization + Recurring modules can read the merchant string
 *     directly without re-running MerchantNameResolver.
 *   - metadata (JSON, nullable) — per-type opaque payload (e.g. the
 *     bank-fee subcategory flag for description-keyword-fallback
 *     rows; the institution-IBAN provenance for known-counterparty
 *     bridge rows).
 *   - timestamps.
 *
 * UNIQUE (user_id, slug) blocks duplicate slug insertion at the
 * database layer; the resolver's collision-suffixing algorithm
 * (`bol`, `bol-2`, `bol-3`, …) depends on this index for correctness.
 * Index (user_id, type) is the hot-path the index page's type-filter
 * chip query hits.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('counterparties', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('slug', 128);
            $table->string('display_name');
            $table->string('iban', 64)->nullable();
            $table->string('merchant_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'type']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedTypes = "'merchant','personal','bank','government','self_account','unknown'";
        $connection->statement(sprintf(
            "CREATE TRIGGER counterparties_type_check_insert BEFORE INSERT ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
            $allowedTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER counterparties_type_check_update BEFORE UPDATE OF type ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS counterparties_type_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS counterparties_type_check_insert');

        $this->schema()->dropIfExists('counterparties');
    }
};
