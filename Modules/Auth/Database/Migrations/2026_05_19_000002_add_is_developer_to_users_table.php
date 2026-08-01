<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `is_developer` flag to the `users` table.
 *
 * The flag (default false) marks a user as eligible for the in-app
 * developer console; the column lives on `users` so the developer-mode
 * middleware can read it without a join.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->boolean('is_developer')->default(false)->after('username');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('is_developer');
        });
    }
};
