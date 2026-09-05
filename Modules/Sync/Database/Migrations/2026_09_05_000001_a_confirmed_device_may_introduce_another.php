<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // Deliberately NOT device_registry. Every confirmed-only query in this
        // module is a `whereNotNull('confirmed_at')` over that table, and a row
        // there is one dropped filter away from a transport peer. A relayed key
        // grants signature verification and nothing else, so it is kept where
        // no session, no handshake and no epoch fan-out can reach it at all.
        $this->schema()->create('device_introductions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('device_id');
            $table->string('name');
            // The Ed25519 signing half only. The X25519 key a Noise handshake
            // authenticates against is never carried by an introduction and has
            // no column here to land in.
            $table->string('ed25519_public_key_hex');
            // Derived HERE from the key that arrived and this device's own, not
            // copied from the voucher: a fingerprint the reader is asked to
            // trust because someone sent it is not a fingerprint.
            $table->text('safety_number_words');
            $table->string('introduced_by_device_id');
            // What the vouching peer is holding back for want of this key. The
            // cost of the catch-up filter is that a device silently ends up with
            // less than the household has; this is the number that unsilences it.
            $table->integer('withheld_entry_count')->default(0);
            $table->text('introduced_at');
            // NULL until the reader acts. Named apart from device_registry's
            // confirmed_at because it grants something strictly weaker, and a
            // query that conflated the two would grant the difference.
            $table->text('verification_confirmed_at')->nullable();
            $table->text('created_at');
            $table->text('updated_at');
        });

        $this->db()->connection($this->getConnection())->statement(
            'CREATE UNIQUE INDEX device_introductions_user_device_idx ON device_introductions (user_id, device_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('device_introductions');
    }
};
