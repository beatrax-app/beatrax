<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The initiator's own device name, carried on the scanned QR.
 *
 * responder_name already travels the other way, which is why the desktop
 * shows "Wessel's S24 Ultra". Nothing carried the name back, so the phone
 * labelled the desktop with the `peer_default_name` placeholder — the sync
 * it had just completed reported as coming from "Paired device".
 *
 * Cosmetic by design, exactly like responder_name: it is not part of any
 * signed material and grants nothing, so a forged name is a wrong caption
 * and never a trust decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pairing_tokens', function (Blueprint $table): void {
            $table->string('initiator_name')->nullable()->after('initiator_seeded_at');
        });
    }

    public function down(): void
    {
        Schema::table('pairing_tokens', function (Blueprint $table): void {
            $table->dropColumn('initiator_name');
        });
    }
};
