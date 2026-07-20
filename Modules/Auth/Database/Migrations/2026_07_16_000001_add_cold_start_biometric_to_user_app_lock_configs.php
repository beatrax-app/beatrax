<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold-start biometric unlock enrollment state (mobile).
 *
 *  - cold_start_biometric_enrolled: whether the user has stored a
 *    biometric-gated data-key blob in the enclave vault. A plain boolean so
 *    the lock screen / settings can check enrollment WITHOUT triggering a
 *    Face ID prompt just to read state.
 *  - last_pin_unlock_at: the PIN-floor anchor. A successful PIN unlock stamps
 *    it; the lock screen offers biometric only when the floor is not overdue
 *    (see cold_start_pin_floor_days config).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_app_lock_configs', function (Blueprint $table): void {
            $table->boolean('cold_start_biometric_enrolled')->default(false)->after('last_activity_at');
            $table->timestamp('last_pin_unlock_at')->nullable()->after('cold_start_biometric_enrolled');
        });
    }

    public function down(): void
    {
        Schema::table('user_app_lock_configs', function (Blueprint $table): void {
            $table->dropColumn(['cold_start_biometric_enrolled', 'last_pin_unlock_at']);
        });
    }
};
