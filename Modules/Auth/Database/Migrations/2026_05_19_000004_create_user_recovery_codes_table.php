<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Consuming a code stamps `used_at` rather than deleting the row, so issued
// and spent codes both survive as an audit chain. That stamp is the only
// post-insert mutation, hence `created_at` alone and no `updated_at`.
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
