<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `community_settings` JSON column to `users` — the per-user
 * key-value store backing the three Settings → Shared merchant list
 * toggles:
 *
 *   - `useSharedList`        — bool; when off, the resolver skips the
 *                               community corpus fallback steps.
 *   - `offerToContribute`    — bool; when off, the Triage row CTA
 *                               that surfaces the suggest-mapping flow
 *                               is hidden.
 *   - `updateOnAppUpdates`   — bool; reserved for the future auto-
 *                               update plumbing; ships as a no-op
 *                               control surface until that mechanism
 *                               lands.
 *
 * The column is nullable so a fresh user row carries `null` until the
 * user opens the Settings panel and saves an explicit set of
 * preferences. The User model exposes the column via `$fillable` and
 * casts it to a PHP array so consumer code reads it as a plain
 * associative array without manual JSON decoding.
 *
 * Placed after the `close_behavior` column so the desktop-shell user
 * preferences (`theme`, `close_behavior`, `community_settings`)
 * cluster together in the schema layout.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasColumn('users', 'community_settings')) {
            $this->schema()->table('users', static function (Blueprint $table): void {
                $table->json('community_settings')
                    ->nullable()
                    ->after('close_behavior');
            });
        }
    }

    public function down(): void
    {
        if ($this->schema()->hasColumn('users', 'community_settings')) {
            $this->schema()->table('users', static function (Blueprint $table): void {
                $table->dropColumn('community_settings');
            });
        }
    }
};
