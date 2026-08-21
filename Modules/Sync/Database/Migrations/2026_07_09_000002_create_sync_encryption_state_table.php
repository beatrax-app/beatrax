<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Device-local, and deliberately absent from MergeRulesRegistry so it
        // never syncs: each device's encryption rollout is its own: a peer
        // catching up mid-rotation must resolve its epoch from the keyring it
        // receives, not from a replicated row about someone else's progress.
        $this->schema()->create('sync_encryption_state', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            // The epoch id alone — this table never holds key material.
            $table->integer('current_epoch')->nullable();
            // True for the duration of the backup-first migration pass, so a
            // crash mid-pass is never mistaken for a completed one.
            $table->boolean('migration_in_progress')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE UNIQUE INDEX sync_encryption_state_user_idx ON sync_encryption_state (user_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('sync_encryption_state');
    }
};
