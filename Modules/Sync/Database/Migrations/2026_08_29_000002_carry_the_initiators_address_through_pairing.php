<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // Rides the same rail as initiator_name, and for the same reason: the
        // offer fetch is the only moment the responder sees where the
        // initiator actually is, and it used to discard it. Carrying the
        // address means the first sync dial does not have to pay a browse —
        // and on a runtime whose multicast is blocked, that browse is the one
        // that never answers.
        $this->schema()->table('pairing_tokens', static function (Blueprint $table): void {
            $table->string('initiator_lan_host')->nullable();
            $table->unsignedInteger('initiator_lan_port')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('pairing_tokens', static function (Blueprint $table): void {
            $table->dropColumn(['initiator_lan_host', 'initiator_lan_port']);
        });
    }
};
