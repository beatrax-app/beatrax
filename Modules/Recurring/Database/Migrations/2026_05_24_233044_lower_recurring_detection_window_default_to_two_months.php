<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Lower the `users.recurring_detection_window_months` default from 18
 * to 2 months and roll any existing user row still parked on the
 * previous default forward to the new one.
 *
 *  - The detector's MIN_OCCURRENCES gate is 2, so a 2-month window is
 *    the smallest signal the engine can act on; the 18-month default
 *    was conservative for cold-starts with no history. Once a user is
 *    actively importing, 2 months covers monthly subscriptions and
 *    bi-monthly pairs without dragging older noise into the sweep.
 *  - Rows whose value differs from the previous default (e.g. a user
 *    explicitly chose 24) are left untouched so a deliberate override
 *    is not clobbered.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // Update every row still parked on the previous default before
        // the schema change so the column rewrite Laravel performs on
        // SQLite (table copy under the hood) does not have to also
        // back-fill — keeps the migration's intent explicit at each
        // step.
        $connection->table('users')
            ->where('recurring_detection_window_months', 18)
            ->update(['recurring_detection_window_months' => 2]);

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('recurring_detection_window_months')
                ->default(2)
                ->change();
        });
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->table('users')
            ->where('recurring_detection_window_months', 2)
            ->update(['recurring_detection_window_months' => 18]);

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('recurring_detection_window_months')
                ->default(18)
                ->change();
        });
    }
};
