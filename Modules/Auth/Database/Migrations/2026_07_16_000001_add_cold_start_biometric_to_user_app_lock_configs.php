<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// cold_start_biometric_enrolled mirrors the enclave vault as a boolean, so the
// lock screen reads enrolment without raising a Face ID prompt to do it.
// last_pin_unlock_at anchors MobileLockGateway::PIN_FLOOR_DAYS.
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
