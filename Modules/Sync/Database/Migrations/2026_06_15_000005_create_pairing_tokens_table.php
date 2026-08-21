<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('pairing_tokens', static function (Blueprint $table): void {
            $table->id();
            // No FK/cascade, as everywhere else in this schema. Whatever
            // eventually deletes an account must sweep this table and
            // device_registry itself, or a removed user leaves live tokens.
            $table->unsignedInteger('user_id');
            // SHA-256(token) hex. The plaintext token is shown to the user as
            // a QR and a word code and is never persisted, so a stolen row
            // yields nothing presentable; validation is hash_equals on this.
            $table->string('token_hash')->unique();
            $table->string('initiator_device_id');
            $table->string('initiator_ed25519_pub_hex');
            $table->string('initiator_x25519_pub_hex');
            $table->string('responder_device_id')->nullable();
            $table->string('responder_ed25519_pub_hex')->nullable();
            $table->string('responder_x25519_pub_hex')->nullable();
            // Application-validated TEXT, never a database enum:
            // pending | awaiting_confirm | confirmed | expired.
            $table->string('state')->default('pending');
            $table->text('expires_at');
            $table->text('accepted_at')->nullable();
            // Both must be set before state may reach confirmed — the
            // safety-number check is mandatory on both devices.
            $table->text('initiator_confirmed_at')->nullable();
            $table->text('responder_confirmed_at')->nullable();
            $table->text('created_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE INDEX pairing_tokens_user_expires_idx ON pairing_tokens (user_id, expires_at, state)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pairing_tokens');
    }
};
