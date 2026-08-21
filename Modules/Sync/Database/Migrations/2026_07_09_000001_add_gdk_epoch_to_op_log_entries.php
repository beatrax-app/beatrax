<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('op_log_entries', static function (Blueprint $table): void {
            // Which keyring epoch can decrypt this entry's value. Plaintext
            // and structural by design — an epoch id, never key material.
            // NULL means the entry predates encryption for this user, or its
            // field is not sensitive; either way the value stays plain JSON.
            $table->integer('gdk_epoch')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        $this->schema()->table('op_log_entries', static function (Blueprint $table): void {
            $table->dropColumn('gdk_epoch');
        });
    }
};
