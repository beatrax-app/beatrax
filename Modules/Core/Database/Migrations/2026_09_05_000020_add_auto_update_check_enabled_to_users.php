<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // Default true because the signed manifest is the only binary-integrity
            // signal this product has, so the shipped posture is on. The switch
            // exists because the check is an outbound call the reader must be able
            // to stop; it is device-local, like `close_behavior` beside it.
            $table->boolean('auto_update_check_enabled')
                ->default(true)
                ->after('close_behavior');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('auto_update_check_enabled');
        });
    }
};
