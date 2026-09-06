<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Device-local for the same reason `last_lan_host` is, and kept apart
        // from it because the two answer different questions: one is where the
        // browse last succeeded and is overwritten by the next one, the other
        // is what a reader typed on a network where the browse never answers
        // at all. Discovery must not be able to erase the fallback for it.
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->string('manual_lan_host')->nullable();
            $table->unsignedInteger('manual_lan_port')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->dropColumn(['manual_lan_host', 'manual_lan_port']);
        });
    }
};
