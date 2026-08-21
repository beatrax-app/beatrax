<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pairing_tokens', function (Blueprint $table): void {
            // The return leg of responder_name, carried on the scanned QR.
            // Nothing brought the initiator's name back, so a phone labelled
            // the desktop it had just synced from with the placeholder name.
            // Cosmetic either way: neither name is part of any signed material.
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
