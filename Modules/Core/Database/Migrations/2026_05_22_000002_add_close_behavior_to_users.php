<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `close_behavior` column to `users` — the per-user window-close
 * preference recorded by the D-08 first-close prompt.
 *
 * Three states:
 *
 *   NULL    — no decision yet; the next window-close shows the
 *             Quit / Keep-in-tray prompt.
 *   'quit'  — the user chose "Quit beatrax" and asked to remember it;
 *             future closes quit the app immediately.
 *   'tray'  — the user chose "Keep running in the tray" and asked to
 *             remember it; future closes hide the window and keep the
 *             D-05 worker + D-06 scheduler alive.
 *
 * The column is nullable so a fresh user row implicitly prompts on the
 * first close — matching the D-08 UI-SPEC behavior. The 8-char string
 * is wide enough for either 'quit' / 'tray' value with room for a
 * future 'minimize' option (not in scope this phase).
 *
 * Placed after the `theme` column (added in plan 15-06) so all
 * desktop-shell user preferences cluster together in the schema.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('close_behavior', 8)
                ->nullable()
                ->after('theme');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('close_behavior');
        });
    }
};
