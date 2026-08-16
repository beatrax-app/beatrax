<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The user id the ORIGIN device signed under.
 *
 * A v1 signature covers user_id, and user_id is a per-device autoincrement,
 * so an entry accepted from a peer is re-scoped onto the local user before it
 * is stored. That re-scope silently destroyed the entry's own signature: live
 * sync passed (it verifies before re-scoping), but every later re-verification
 * — the rebuild that re-projection runs — saw a payload that no longer matched
 * and quarantined the whole log as forged, leaving the device with an empty app.
 *
 * Nullable: rows written by a device signing v2 (which excludes user_id) need
 * nothing here, and existing rows keep working through the null fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_log_entries', function (Blueprint $table): void {
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
