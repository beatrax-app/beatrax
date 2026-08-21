<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Redemption looks a code up by its hash, so at most one row may ever match.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_recovery_codes', static function (Blueprint $table): void {
            $table->unique('code_hash', 'user_recovery_codes_code_hash_unique');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_recovery_codes', static function (Blueprint $table): void {
            $table->dropUnique('user_recovery_codes_code_hash_unique');
        });
    }
};
