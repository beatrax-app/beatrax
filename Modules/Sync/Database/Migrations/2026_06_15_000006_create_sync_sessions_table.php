<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('sync_sessions', static function (Blueprint $table): void {
            $table->id();
            // No FK/cascade, as everywhere else in this schema. Whatever
            // eventually deletes an account must sweep this table itself.
            $table->unsignedInteger('user_id');
            $table->string('local_device_id');
            $table->string('peer_device_id');
            // Application-validated TEXT, never a database enum:
            // active | closed | failed.
            $table->string('status', 32);
            $table->text('error_message')->nullable();
            // The instant a valid encrypted message last arrived on this
            // session — finer-grained than device_registry.last_seen_at.
            $table->text('last_seen_at')->nullable();
            $table->text('connected_at')->nullable();
            $table->text('created_at');
            $table->text('updated_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE INDEX sync_sessions_user_peer_idx ON sync_sessions (user_id, peer_device_id, status)'
        );

        $connection->statement(
            'CREATE UNIQUE INDEX sync_sessions_user_peer_unique ON sync_sessions (user_id, local_device_id, peer_device_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('sync_sessions');
    }
};
