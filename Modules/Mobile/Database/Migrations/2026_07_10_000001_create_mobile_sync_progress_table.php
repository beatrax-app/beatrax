<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('mobile_sync_progress', static function (Blueprint $table): void {
            $table->id();
            // Plain column, no FK and no reliance on the BelongsToUser global
            // scope: this cursor is read and written from background and
            // console contexts that carry no authenticated session.
            $table->unsignedInteger('user_id');
            $table->string('peer_device_id');
            // Nullable because the peer's total is unknown until the first
            // catch-up exchange completes.
            $table->unsignedInteger('records_expected')->nullable();
            $table->unsignedInteger('records_applied')->default(0);
            $table->unsignedBigInteger('last_hlc_l')->default(0);
            $table->unsignedInteger('last_hlc_c')->default(0);
            // Application-validated TEXT: the vocabulary is SyncPhase, whose
            // backing values are exactly the strings this column stores.
            $table->text('phase')->default('pending');
            $table->text('created_at');
            $table->text('updated_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE UNIQUE INDEX mobile_sync_progress_user_peer_idx ON mobile_sync_progress (user_id, peer_device_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('mobile_sync_progress');
    }
};
