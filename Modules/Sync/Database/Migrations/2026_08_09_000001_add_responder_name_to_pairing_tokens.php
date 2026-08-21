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
            // The responder's own name, parked here between its accept frame
            // and admission because that is when the registry row is created.
            // Without it the admitter named every peer with its own detector
            // and a paired phone showed up as "This device (Mac)".
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
