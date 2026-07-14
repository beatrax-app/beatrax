<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

/**
 * Durable, per-user marker of whether THIS device's local account
 * originated from the "Import from another device" flow
 * (`MobileImportBootstrap`) — fixes MEDIUM-01 and LOW-01 from
 * `15-import-join-REVIEW.md`.
 *
 * Both findings shared the same root cause: the import-vs-normal
 * distinction previously lived ONLY in `?mode=import`, re-read from the
 * request query on every `MobilePairingScan::mount()` — a signal that does
 * not survive a re-entry without the param (back button, bookmark, a
 * relaunched/tombstoned NativePHP process). This gate replaces that with a
 * DURABLE, server-side marker, written by two independent, idempotent
 * callers:
 *
 *   1. `MobileImportBootstrap::provisionDeviceLocally()` — the
 *      AUTHORITATIVE source: marks the intent right when the fresh-device
 *      identity bootstrap actually runs `enableSyncIdentityWithoutEpoch()`
 *      (the true origin of "this device is importing, deferring its own
 *      epoch mint").
 *   2. `MobilePairingScan::mount()` — a defense-in-depth ECHO: marks the
 *      intent the moment `?mode=import` is observed, so a later re-entry
 *      to `/mobile/pair` WITHOUT the query param still reads the durable
 *      marker this same visit already persisted.
 *
 * Consumed by:
 *   - `MobilePairingScan::confirmMatch()`/`checkPairingState()` (MEDIUM-01):
 *     skip the self-mint `EncryptionMigrationService::migrate()` call only
 *     when BOTH the intent marker is present AND the keyring is still
 *     empty — never on the marker alone, so a device that already
 *     converged its keyring (e.g. a second, unrelated pairing later) is
 *     unaffected.
 *   - `InitialSyncPuller::pull()` (LOW-01): keep the setup gate blocking
 *     (`phase` stays `pulling`, never `complete`) when a relay-only leg
 *     reports success but the keyring is still empty for a marked-import
 *     device — a non-import "encryption never enabled" completion (a
 *     legitimately, permanently empty keyring) is unaffected because no
 *     marker exists for that user.
 *
 * No key material, no new trust decision — a plain marker consumed only to
 * pick between two otherwise-ambiguous UI/completion states. Never read by
 * any crypto/admission code path (`PairingTokenService`,
 * `GdkEpochControlHandler`, `GdkRotationService` all remain unaware of it).
 */
final class MobileImportIntentGate
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Durably mark $userId's device as having originated from (or having
     * ever observed) the import flow. Idempotent — a second call for the
     * same user is a no-op, never a duplicate row.
     */
    public function markImporting(int $userId): void
    {
        $exists = $this->db->connection()
            ->table('mobile_import_intent')
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return;
        }

        $this->db->connection()->table('mobile_import_intent')->insert([
            'user_id' => $userId,
            'created_at' => $this->clock->now()->toIso8601String(),
        ]);
    }

    /**
     * True once $userId has ever been marked via markImporting().
     */
    public function isImporting(int $userId): bool
    {
        return $this->db->connection()
            ->table('mobile_import_intent')
            ->where('user_id', $userId)
            ->exists();
    }
}
