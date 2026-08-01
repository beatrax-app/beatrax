<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the user_biometric_credentials table — one row per enrolled
 * biometric device per user.
 *
 * Each enrollment stores a device-specific biometric_wrap_secret (raw
 * binary, used to wrap the data key during biometric unlock), the
 * credential_id (opaque device identifier from WebAuthn or NativePHP),
 * and a counter for authenticator replay protection.
 *
 * The platform column stores 'nativephp_macos' or 'webauthn' as a plain
 * string — no DB enum, consistent with the project convention. Validation
 * of acceptable platform values is the application layer's responsibility.
 *
 * biometric_failed_count tracks consecutive biometric failures per device;
 * it is reset to 0 on a successful authentication.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('user_biometric_credentials', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('credential_id');
            $table->string('device_label');
            $table->binary('biometric_wrap_secret');
            $table->text('public_key_cbor')->nullable();
            $table->unsignedBigInteger('counter')->default(0);
            $table->string('platform');
            $table->unsignedTinyInteger('biometric_failed_count')->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_biometric_credentials');
    }
};
