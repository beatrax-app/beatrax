<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', static function (Blueprint $table): void {
            $table->unsignedInteger('enriched_count')->default(0)->after('duplicate_count');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', static function (Blueprint $table): void {
            $table->dropColumn('enriched_count');
        });
    }
};
