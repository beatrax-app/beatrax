<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `reports_index_view` column to the existing `user_preferences`
 * foundation table — the per-user preference for the `/reports/library`
 * index view mode (cards | list).
 *
 * Mirrors `2026_05_28_000001_add_counterparty_index_view_to_user_preferences.php`
 * (999.6-PATTERNS.md "user_preferences precedent") exactly: default `cards`
 * matches the UI-SPEC locked default; existing rows materialise the default
 * at the DB boundary so an Eloquent fetch immediately after migrate returns
 * the canonical value without a backfill pass.
 *
 * Additive-only — does NOT (re)create `user_preferences`. If that table is
 * missing, the ALTER TABLE fails loud, surfacing the broken foundation
 * dependency instead of silently re-creating it.
 *
 * Container-DI Migrator pattern: the `DatabaseManager` is resolved lazily
 * via `Container::getInstance()->make()` rather than the `DB` facade,
 * keeping the migration aligned with the project's DI-only invariant.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->string('reports_index_view', 16)->default('cards');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn('reports_index_view');
        });
    }
};
