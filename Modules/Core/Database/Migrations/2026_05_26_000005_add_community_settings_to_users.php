<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasColumn('users', 'community_settings')) {
            $this->schema()->table('users', static function (Blueprint $table): void {
                $table->json('community_settings')
                    ->nullable()
                    ->after('close_behavior');
            });
        }
    }

    public function down(): void
    {
        if ($this->schema()->hasColumn('users', 'community_settings')) {
            $this->schema()->table('users', static function (Blueprint $table): void {
                $table->dropColumn('community_settings');
            });
        }
    }
};
