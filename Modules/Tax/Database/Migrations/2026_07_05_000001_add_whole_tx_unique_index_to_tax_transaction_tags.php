<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;

/**
 * DB-level backstop restoring whole-transaction-tag uniqueness (Phase 13.3
 * Finding B).
 *
 * 2026_07_04_000002_add_transaction_split_id_to_tax_transaction_tags widened
 * the unique constraint from `unique(user_id, transaction_id)` to
 * `unique(user_id, transaction_id, transaction_split_id)` so a leg-scoped
 * tag (transaction_split_id NOT NULL) and a whole-tx tag (transaction_split_id
 * IS NULL) on the same transaction could coexist. SQLite treats every NULL
 * as a DISTINCT value for uniqueness purposes though, so that compound
 * constraint no longer rejects TWO whole-tx rows for the same
 * (user_id, transaction_id) — both have transaction_split_id = NULL, and
 * NULL never equals NULL. This silently broke TagTransaction's IN-06
 * select-then-insert race guard, which relies on catching
 * UniqueConstraintViolationException on a lost race: a double-clicked "Tag"
 * button could create two whole-tx rows and TaxYearQuery would double-count
 * the deduction.
 *
 * This migration adds a PARTIAL unique index — `WHERE transaction_split_id
 * IS NULL` — which SQLite (and the planned PostgreSQL migration target,
 * per the Pots precedent this mirrors) both support, restoring one-row-per
 * whole-transaction-tag at the schema level. The per-leg compound unique
 * index from the prior migration is untouched and continues to guarantee
 * one row per (user_id, transaction_id, transaction_split_id) for non-NULL
 * split ids.
 *
 * Pure additive/backstop schema change — no data migration or backfill.
 * See Modules/Pots/Database/Migrations/2026_06_10_000003_add_active_goal_unique_index_to_pots.php
 * for the closest existing partial-unique-index precedent.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->connection()->statement(
            'CREATE UNIQUE INDEX tax_tags_whole_tx_unique ON tax_transaction_tags (user_id, transaction_id) WHERE transaction_split_id IS NULL'
        );
    }

    public function down(): void
    {
        $this->connection()->statement('DROP INDEX IF EXISTS tax_tags_whole_tx_unique');
    }

    private function connection(): Connection
    {
        return $this->db()->connection($this->getConnection());
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
