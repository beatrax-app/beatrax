<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('device_registry', static function (Blueprint $table): void {
            $table->id();
            // No FK/cascade, as everywhere else in this schema. Whatever
            // eventually deletes an account must sweep this table and
            // pairing_tokens itself, or a removed user leaves trusted keys.
            $table->unsignedInteger('user_id');
            $table->string('device_id');
            $table->string('name');
            // Public halves only. Secret keys live exclusively in the
            // encrypted key file on disk; a database column for one would
            // put the whole trust root in every backup.
            $table->string('ed25519_public_key_hex');
            $table->string('x25519_public_key_hex');
            $table->text('safety_number_words');
            $table->integer('is_self')->default(0);
            $table->text('paired_at');
            // NULL until both sides confirm the safety number. deviceKeys()
            // returns confirmed rows only, so this column is what decides
            // which signatures the replayer will trust.
            $table->text('confirmed_at')->nullable();
            $table->text('last_seen_at')->nullable();
            $table->text('created_at');
            $table->text('updated_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE UNIQUE INDEX device_registry_user_device_idx ON device_registry (user_id, device_id)'
        );

        $connection->statement(
            'CREATE INDEX device_registry_user_idx ON device_registry (user_id, confirmed_at)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('device_registry');
    }
};
