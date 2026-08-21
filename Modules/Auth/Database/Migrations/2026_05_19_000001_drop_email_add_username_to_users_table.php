<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Three ALTER TABLE passes: SQLite rebuilds the table for each structural
// change and cannot drop and add a uniquely-indexed column in one. `unique()`
// is case-sensitive; the application lowercases every username before a write.
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
