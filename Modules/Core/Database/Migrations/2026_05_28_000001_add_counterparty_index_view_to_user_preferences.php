<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->string('counterparty_index_view', 16)->default('cards');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn('counterparty_index_view');
        });
    }
};
