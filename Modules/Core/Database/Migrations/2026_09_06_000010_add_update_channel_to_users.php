<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // Stable by default because a reader who never opened this screen
            // has not opted into early builds. The channel was an environment
            // variable baked into the bundle before this, so choosing preview
            // meant rebuilding rather than deciding.
            $table->string('update_channel', 16)
                ->default('stable')
                ->after('auto_update_check_enabled');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('update_channel');
        });
    }
};
