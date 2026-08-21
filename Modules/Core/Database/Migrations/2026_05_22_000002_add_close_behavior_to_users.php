<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // NULL is "not asked yet", which is what makes the next window
            // close show the quit-or-tray prompt. 'quit' and 'tray' are the
            // remembered answers.
            $table->string('close_behavior', 8)
                ->nullable()
                ->after('theme');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('close_behavior');
        });
    }
};
