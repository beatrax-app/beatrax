<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the user_recovery_codes table — one row per single-use
 * account-recovery code issued to a user at signup.
 *
 * Each code is stored as a one-way hash; consuming a code stamps
 * `used_at` rather than deleting the row, so the full set of issued
 * and consumed codes survives as an audit chain. The table therefore
 * carries `created_at` but deliberately omits `updated_at`: the only
 * post-insert mutation is the `used_at` stamp.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('user_recovery_codes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_recovery_codes');
    }
};
