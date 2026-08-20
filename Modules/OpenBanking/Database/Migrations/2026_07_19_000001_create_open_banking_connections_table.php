<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Metadata only: no credential column may ever be added to this table,
// since a secret in SQLite would leak into every DB backup the user takes.
// OpenBankingSecretsFileGuardTest greps this file to enforce that.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('open_banking_connections', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('institution_id', 64);
            $table->string('bank_display_name', 128)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('consent_expires_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_attempt_status', 32)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'institution_id']);
            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('open_banking_connections');
    }
};
