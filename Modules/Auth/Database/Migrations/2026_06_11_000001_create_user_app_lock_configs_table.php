<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the user_app_lock_configs table — one row per user, holding the
 * Argon2id PIN hash, KDF salt, wrapped data-key blobs, and lock-state
 * bookkeeping columns that drive the app-lock feature.
 *
 * Key-wrapping blobs (pin_wrapped_key, password_wrapped_key) are stored as
 * TEXT because they are base64-encoded nonce||ciphertext produced by
 * AppLockKeyWrap. The pin_hash column carries the self-describing
 * sodium_crypto_pwhash_str() verifier string (starts with `$argon2id$`).
 * The kdf_salt column is a 16-byte raw binary value used by AppLockKdf.
 *
 * The platform column on the companion user_biometric_credentials table
 * stores 'nativephp_macos' or 'webauthn' as a plain string — no DB enum
 * is used, consistent with the project's no-DB-enum convention (validation
 * lives in application code).
 */
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
            $table->unsignedTinyInteger('idle_timeout_minutes')->default(5);
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
