<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', static function (Blueprint $table): void {
            $table->json('enriched_from')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('enriched_from');
        });
    }
};
