<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the `user_preferences` table — the shared per-user
 * single-row preference store consumed across domain modules.
 *
 * Schema:
 *
 *   - id (auto-increment PK).
 *   - user_id (FK users.id, cascadeOnDelete) — per-user scope.
 *   - created_at, updated_at — standard timestamps.
 *
 * UNIQUE (user_id) enforces the "one preference row per user"
 * invariant at the database boundary; the application layer reads and
 * writes a single row per authenticated user (upsert pattern).
 *
 * Feature columns live on additive column-add migrations owned by the
 * consuming domain modules — keeping this foundation migration narrow
 * means downstream modules can each ship their own preference field
 * without coordinating timestamps or contending on a shared schema
 * change.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('user_preferences', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_preferences');
    }
};
