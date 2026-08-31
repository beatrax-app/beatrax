<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Auth\Internal\Lock\IdleTimeoutOptions;
use Modules\Core\Database\Support\ModuleMigration;

// Blob shapes the column types cannot carry: pin_hash is the self-describing
// sodium_crypto_pwhash_str() verifier, kdf_salt is 16 raw bytes, and the
// *_wrapped_key columns are AppLockKeyWrap's base64 nonce||ciphertext.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('user_app_lock_configs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('pin_hash')->nullable();
            $table->binary('kdf_salt')->nullable();
            $table->text('pin_wrapped_key')->nullable();
            $table->text('password_wrapped_key')->nullable();
            $table->boolean('lock_enabled')->default(false);
            $table->unsignedTinyInteger('idle_timeout_minutes')->default(IdleTimeoutOptions::DEFAULT_MINUTES);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_app_lock_configs');
    }
};
