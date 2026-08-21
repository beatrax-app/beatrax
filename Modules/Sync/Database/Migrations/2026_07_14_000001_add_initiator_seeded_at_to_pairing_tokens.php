<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('pairing_tokens', static function (Blueprint $table): void {
            // Set only by seedFromInitiator(), so it marks a row that carries
            // a real scanned identity. admitInitiatorDevice() is gated on it:
            // rows from issue() hold a caller-supplied initiator id that may
            // be a placeholder, which must never become a registry entry.
            $table->text('initiator_seeded_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        $this->schema()->table('pairing_tokens', static function (Blueprint $table): void {
            $table->dropColumn('initiator_seeded_at');
        });
    }
};
