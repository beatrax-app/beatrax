<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Reshapes the `users` table so the authentication identity is a
 * `username` rather than an email address.
 *
 *  - Drops the `email` column.
 *  - Adds `username` (string, unique) as the login identifier.
 *
 * The unique index on `email` is dropped before the column, then the
 * drop and add run in two separate ALTER TABLE callbacks: SQLite
 * rebuilds the table for each structural change and cannot drop a
 * uniquely-indexed column or add a uniquely-indexed column in a single
 * pass. The `unique()` index on `username` gives case-sensitive
 * uniqueness; case-insensitive uniqueness is enforced at the
 * application layer, which lowercases every username before it reaches
 * a write path.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
        });

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('email');
        });

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('username')->unique()->after('id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropUnique('users_username_unique');
        });

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('username');
        });

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('email')->nullable()->after('id');
        });
    }
};
