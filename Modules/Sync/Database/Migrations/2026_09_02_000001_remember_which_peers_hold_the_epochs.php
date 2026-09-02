<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Device-local, like the rest of this table: it records what THIS
        // device has handed over, which the peer cannot restate. Null is the
        // debt — a confirmed peer that was never fanned out to holds an op log
        // it cannot decrypt, and until now nothing knew that had happened.
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->string('epochs_delivered_at')->nullable();
        });

        // Left null for every existing peer on purpose. Re-delivery is
        // idempotent — an epoch already in the keyring returns Applied — so the
        // first request after this upgrade repays the debt on any device the
        // missed fan-out already stranded, rather than leaving it stranded.
    }

    public function down(): void
    {
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->dropColumn('epochs_delivered_at');
        });
    }
};
