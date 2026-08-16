<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The responder's own device name, carried on its PAIR_RESPONDER_ACCEPT frame.
 *
 * Without it the admitting device named every peer with its OWN detector, so a
 * paired phone appeared in the desktop's list as "This device (Mac)". The name
 * has to survive from accept until admission, which is when the device_registry
 * row is finally created, so it rides on the pairing row in between.
 *
 * Cosmetic by design: it is not part of the signed confirm message and grants
 * nothing, so a forged name is a wrong caption and never a trust decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pairing_tokens', function (Blueprint $table): void {
            $table->string('responder_name')->nullable()->after('responder_x25519_pub_hex');
        });
    }

    public function down(): void
    {
        Schema::table('pairing_tokens', function (Blueprint $table): void {
            $table->dropColumn('responder_name');
        });
    }
};
