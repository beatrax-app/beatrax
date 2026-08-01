<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `initiator_seeded_at` column to `pairing_tokens` — Phase 15
 * import-join (G1/G2) marker distinguishing a row seeded LOCALLY from a
 * scanned QR's initiator identity (`PairingTokenService::seedFromInitiator()`)
 * from the NORMAL same-database `issue()`-created row every existing
 * desktop-to-desktop pairing flow uses.
 *
 * ## Why this column exists (correctness-critical, not cosmetic)
 *
 * `PairingTokenService::confirm()`'s existing `bothConfirmed()` transition
 * always admits the RESPONDER device into the local registry
 * (`admitResponderDevice()`) — this is the ONLY admission Phase 12 ever
 * shipped, and every existing single-database test (`PairingFlowTest`,
 * `PairingTrustGateTest`, `MobilePairingScanTest`) depends on that being
 * unconditional. Phase 15 import-join additionally needs the RESPONDER side
 * (a genuinely separate phone database) to admit the INITIATOR
 * (`admitInitiatorDevice()`) — but ONLY for a row that actually carries a
 * real, scanned initiator identity, never for the arbitrary placeholder
 * `initiator_device_id` strings existing tests pass to `issue()` (e.g.
 * `'device-init'`), which are not real devices and must never be inserted
 * as a phantom `device_registry` row. Gating `admitInitiatorDevice()` on
 * `initiator_seeded_at IS NOT NULL` keeps every existing same-database test
 * green while enabling the new cross-device admission path.
 *
 * Column notes:
 *   - NULLABLE TEXT ISO8601 timestamp, set ONLY by
 *     `PairingTokenService::seedFromInitiator()` — NULL for every
 *     `issue()`-created row (the desktop "show my code" path, unchanged).
 *   - No key material, no new trust decision — a plain marker of WHICH
 *     insert path created the row.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('pairing_tokens', static function (Blueprint $table): void {
            $table->text('initiator_seeded_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        $this->schema()->table('pairing_tokens', static function (Blueprint $table): void {
            $table->dropColumn('initiator_seeded_at');
        });
    }
};
