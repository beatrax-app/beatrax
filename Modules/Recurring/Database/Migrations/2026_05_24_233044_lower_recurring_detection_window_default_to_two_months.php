<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The detector's MIN_OCCURRENCES gate is 2, so two months is the smallest window
// the engine can act on; the old 18-month default was cold-start caution. Only
// rows still on that default move — a user who chose 24 is not clobbered.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // Rows still on the old default move before the schema change, so the
        // SQLite table copy ->change() performs does not also have to back-fill.
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
