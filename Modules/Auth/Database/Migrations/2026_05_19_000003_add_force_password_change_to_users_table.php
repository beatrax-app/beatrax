<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `force_password_change_at_next_login` flag to the `users`
 * table.
 *
 * When true (default false) the user must set a new password before
 * any other authenticated action is allowed — the flag drives the
 * owner-resets-partner recovery flow, where the owner assigns a
 * temporary password the partner is required to replace.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->boolean('force_password_change_at_next_login')
                ->default(false)
                ->after('is_developer');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('force_password_change_at_next_login');
        });
    }
};
