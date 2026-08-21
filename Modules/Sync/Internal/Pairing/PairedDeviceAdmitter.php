<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final class PairedDeviceAdmitter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SafetyNumberDeriver $safetyNumberDeriver,
    ) {}

    // Admits the confirmed RESPONDER identity into device_registry.
    // Idempotent: a re-confirm updates the existing (user_id, device_id)
    // row rather than inserting a duplicate.
    public function admitResponderDevice(\stdClass $row, int $userId): void
    {
        $this->admitDevice(
            deviceId: $this->asString($row->responder_device_id),
            publicEdHex: $this->asString($row->responder_ed25519_pub_hex),
            publicKxHex: $this->asString($row->responder_x25519_pub_hex),
            initiatorEdHex: $this->asString($row->initiator_ed25519_pub_hex),
            responderEdHex: $this->asString($row->responder_ed25519_pub_hex),
            userId: $userId,
            deviceName: $this->asString($row->responder_name ?? null),
        );
    }

    // The counterpart of admitResponderDevice() (same shape). Only ever
    // called from the initiator_seeded_at branch — never for a placeholder
    // issue()-created row (see @link).
    public function admitInitiatorDevice(\stdClass $row, int $userId): void
    {
        $this->admitDevice(
            deviceId: $this->asString($row->initiator_device_id),
            publicEdHex: $this->asString($row->initiator_ed25519_pub_hex),
            publicKxHex: $this->asString($row->initiator_x25519_pub_hex),
            initiatorEdHex: $this->asString($row->initiator_ed25519_pub_hex),
            responderEdHex: $this->asString($row->responder_ed25519_pub_hex),
            userId: $userId,
            deviceName: $this->asString($row->initiator_name ?? null),
        );
    }

    // The safety-number words always derive from (initiatorEd, responderEd)
    // in that fixed order, so both sides of a pairing store the identical
    // word list. The self-device guard blocks a crafted peer device_id from
    // ever overwriting this user's own self-row keys.
    private function admitDevice(
        ?string $deviceId,
        ?string $publicEdHex,
        ?string $publicKxHex,
        ?string $initiatorEdHex,
        ?string $responderEdHex,
        int $userId,
        ?string $deviceName = null,
    ): void {
        if ($deviceId === null
            || $publicEdHex === null
            || $publicKxHex === null
            || $initiatorEdHex === null
            || $responderEdHex === null) {
            return;
        }

        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (is_string($selfDeviceId) && hash_equals($selfDeviceId, $deviceId)) {
            return;
        }

        $safetyWords = implode(' ', $this->safetyNumberDeriver->deriveWords($initiatorEdHex, $responderEdHex));
        $now = $this->clock->now()->toIso8601String();

        // Scope the lookup/update to NON-self rows — defense-in-depth so an
        // admit can never mutate the local self-row even if the collision
        // guard above is ever bypassed.
        $existing = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('is_self', 0)
            ->first();

        if ($existing !== null) {
            $this->db->connection()->table('device_registry')
                ->where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->where('is_self', 0)
                ->update([
                    'ed25519_public_key_hex' => $publicEdHex,
                    'x25519_public_key_hex' => $publicKxHex,
                    'safety_number_words' => $safetyWords,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            // The peer's own name from its accept frame, else a placeholder.
            // NEVER deviceNameDetector: that reports THIS machine, which is
            // how a paired phone showed up as "This device (Mac)".
            'name' => $deviceName !== null && $deviceName !== ''
                ? $deviceName
                : Lang::get('sync::devices.peer_default_name'),
            'ed25519_public_key_hex' => $publicEdHex,
            'x25519_public_key_hex' => $publicKxHex,
            'safety_number_words' => $safetyWords,
            'is_self' => 0,
            'paired_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
