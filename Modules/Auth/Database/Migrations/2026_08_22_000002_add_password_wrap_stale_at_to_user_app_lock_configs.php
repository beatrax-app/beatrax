<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// password_wrapped_key is wrapped under the account password itself, so a
// password change that cannot re-wrap it leaves a blob nothing opens. Stamped
// rather than cleared: the blob still opens under the OLD password, and the
// only honest way back is a user who supplies both credentials at once.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_app_lock_configs', static function (Blueprint $table): void {
            $table->timestamp('password_wrap_stale_at')->nullable()->after('password_wrapped_key');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_app_lock_configs', static function (Blueprint $table): void {
            $table->dropColumn('password_wrap_stale_at');
        });
    }
};
