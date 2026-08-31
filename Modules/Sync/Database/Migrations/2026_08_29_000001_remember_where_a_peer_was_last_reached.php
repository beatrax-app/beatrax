<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Device-local, like the rest of this table: an address is true of the
        // network this device sits on, so it must never replicate. A phone that
        // paired by typed code learned the desktop's address from the browse
        // that fetched the offer and then threw it away, leaving the initial
        // sync with nowhere to dial and a screen blaming the network.
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->string('last_lan_host')->nullable();
            $table->unsignedInteger('last_lan_port')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->dropColumn(['last_lan_host', 'last_lan_port']);
        });
    }
};
