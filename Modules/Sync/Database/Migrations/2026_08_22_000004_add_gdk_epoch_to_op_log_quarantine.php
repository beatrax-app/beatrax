<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#telling-not-yet-openable-apart-from-never-openable-here
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('op_log_quarantine', static function (Blueprint $table): void {
            // Which epoch the quarantined entry needs, copied from the entry.
            // Without it the audit row cannot be told apart from one this
            // device will never open, and a recovery pass has to replay the
            // whole history to find out — every sync, forever.
            $table->integer('gdk_epoch')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        $this->schema()->table('op_log_quarantine', static function (Blueprint $table): void {
            $table->dropColumn('gdk_epoch');
        });
    }
};
