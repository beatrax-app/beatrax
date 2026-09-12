<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// A restore brings the old machine's self row and never its key-file, and the
// repair takes is_self off that row rather than deleting it: the restored op
// log is signed by that device_id, and confirmed_at is what a rebuild verifies
// it against. Stamped rather than revoked, so the list can leave it out.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            // A string, like every other instant on this table: the column
            // holds a Zulu literal, and a timestamp type here would compare
            // against the rest of the row in a different frame.
            $table->string('self_retired_at')->nullable()->after('is_self');
        });
    }

    public function down(): void
    {
        $this->schema()->table('device_registry', static function (Blueprint $table): void {
            $table->dropColumn('self_retired_at');
        });
    }
};
