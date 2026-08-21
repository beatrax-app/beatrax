<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_log_entries', function (Blueprint $table): void {
            // The user id the origin device signed under, kept so a v1
            // signature survives the re-scope onto the local user. Without it
            // the rebuild re-verified against a changed payload and
            // quarantined the whole log.
            $table->unsignedBigInteger('origin_user_id')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('op_log_entries', function (Blueprint $table): void {
            $table->dropColumn('origin_user_id');
        });
    }
};
