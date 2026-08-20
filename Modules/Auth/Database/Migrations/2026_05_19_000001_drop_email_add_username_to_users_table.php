<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Three separate ALTER TABLE passes: SQLite rebuilds the table for each
// structural change and can neither drop nor add a uniquely-indexed column
// within one. `unique()` is case-sensitive; case-insensitive uniqueness comes
// from the application lowercasing every username before a write path.
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
