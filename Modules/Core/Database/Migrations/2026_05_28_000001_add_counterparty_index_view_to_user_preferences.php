<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `counterparty_index_view` column to the existing
 * `user_preferences` foundation table — the per-user preference for the
 * `/counterparties` index view mode (cards | list).
 *
 * Default `cards` matches the UI-SPEC locked default; existing rows
 * (created by 17-04a foundation) materialise the default at the DB
 * boundary so an Eloquent fetch immediately after migrate returns the
 * canonical value without a backfill pass.
 *
 * The migration is additive — it does NOT (re)create the table. If the
 * `user_preferences` table is missing, the underlying ALTER TABLE fails
 * loud, surfacing the broken `depends_on: 17-04a` contract instead of
 * silently re-creating a foundation row.
 *
 * Container-DI Migrator pattern: the `DatabaseManager` is resolved
 * lazily via `Container::getInstance()->make()` rather than the
 * `DB` facade, keeping the migration aligned with the project's
 * DI-only invariant for production code.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->string('counterparty_index_view', 16)->default('cards');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn('counterparty_index_view');
        });
    }
};
