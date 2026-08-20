<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('inbox_messages', static function (Blueprint $table): void {
            $table->string('matcher_key', 64)->nullable()->after('status');
            $table->index(['inbox_id', 'matcher_key']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('inbox_messages', static function (Blueprint $table): void {
            $table->dropIndex(['inbox_id', 'matcher_key']);
            $table->dropColumn('matcher_key');
        });
    }
};
