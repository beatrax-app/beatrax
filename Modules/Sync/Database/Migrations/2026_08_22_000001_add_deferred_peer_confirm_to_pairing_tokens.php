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
            // A peer confirm that arrives before the local human has compared
            // the words is held here rather than dropped, so this device's own
            // tap can finish the ceremony without the peer sending again — a
            // peer that has itself reached confirmed never does.
            $table->text('deferred_peer_confirm')->nullable()->after('responder_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pairing_tokens', function (Blueprint $table): void {
            $table->dropColumn('deferred_peer_confirm');
        });
    }
};
