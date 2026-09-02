<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Dto\PairingPeerIdentity;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairedDeviceAdmitter
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SafetyNumberDeriver $safetyNumberDeriver,
    ) {}

    // Admits the confirmed RESPONDER identity into device_registry.
    // Idempotent: a re-confirm updates the existing (user_id, device_id)
    // row rather than inserting a duplicate.
    public function admitResponderDevice(\stdClass $row, int $userId): void
    {
        $this->admitDevice(
            peer: $this->peerFromRow(
                $row->responder_device_id,
                $row->responder_ed25519_pub_hex,
                $row->responder_x25519_pub_hex,
                $row->responder_name ?? null,
            ),
            initiatorEdHex: $this->asString($row->initiator_ed25519_pub_hex),
            responderEdHex: $this->asString($row->responder_ed25519_pub_hex),
            userId: $userId,
        );
    }

    // The counterpart of admitResponderDevice() (same shape). Only ever
    // called from the initiator_seeded_at branch — never for a placeholder
    // issue()-created row (see @link).
    public function admitInitiatorDevice(\stdClass $row, int $userId): void
    {
        $this->admitDevice(
            peer: $this->peerFromRow(
                $row->initiator_device_id,
                $row->initiator_ed25519_pub_hex,
                $row->initiator_x25519_pub_hex,
                $row->initiator_name ?? null,
                $row->initiator_lan_host ?? null,
                $row->initiator_lan_port ?? null,
            ),
            initiatorEdHex: $this->asString($row->initiator_ed25519_pub_hex),
            responderEdHex: $this->asString($row->responder_ed25519_pub_hex),
            userId: $userId,
        );
    }

    // A pairing_tokens row is untyped, so the identity is only an identity once
    // all three key columns read as strings. Null here is the same refusal the
    // admit makes on a malformed row: nothing is written.
    private function peerFromRow(
        mixed $deviceId,
        mixed $edHex,
        mixed $kxHex,
        mixed $deviceName,
        mixed $lanHost = null,
        mixed $lanPort = null,
    ): ?PairingPeerIdentity {
        $deviceId = $this->asString($deviceId);
        $edHex = $this->asString($edHex);
        $kxHex = $this->asString($kxHex);

        if ($deviceId === null || $edHex === null || $kxHex === null) {
            return null;
        }

        return new PairingPeerIdentity(
            $deviceId,
            $edHex,
            $kxHex,
            $this->asString($deviceName),
            $this->asString($lanHost),
            $this->asInt($lanPort),
        );
    }

    // The safety-number words always derive from (initiatorEd, responderEd)
    // in that fixed order, so both sides of a pairing store the identical
    // word list. The self-device guard blocks a crafted peer device_id from
    // ever overwriting this user's own self-row keys.
    private function admitDevice(
        ?PairingPeerIdentity $peer,
        ?string $initiatorEdHex,
        ?string $responderEdHex,
        int $userId,
    ): void {
        if ($peer === null || $initiatorEdHex === null || $responderEdHex === null) {
            return;
        }

        $deviceId = $peer->deviceId;

        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (is_string($selfDeviceId) && hash_equals($selfDeviceId, $deviceId)) {
            return;
        }

        $safetyWords = implode(' ', $this->safetyNumberDeriver->deriveWords($initiatorEdHex, $responderEdHex));
        $now = Instant::zulu($this->clock->now());

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
                    'ed25519_public_key_hex' => $peer->ed25519PubHex,
                    'x25519_public_key_hex' => $peer->x25519PubHex,
                    'safety_number_words' => $safetyWords,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                    // Admitting a device means it is owed every epoch again,
                    // and removal mints a fresh one it has never held. Left
                    // stamped from the previous pairing, this row would read as
                    // already-delivered and nothing would ever send them.
                    'epochs_delivered_at' => null,
                    // Conditional: a re-admit over the relay carries no
                    // address, and writing null would throw away the one the
                    // LAN pairing already recorded.
                    ...($peer->lanHost !== null && $peer->lanPort !== null
                        ? ['last_lan_host' => $peer->lanHost, 'last_lan_port' => $peer->lanPort]
                        : []),
                ]);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            // The peer's own name from its accept frame, else a placeholder.
            // NEVER deviceNameDetector: that reports THIS machine, which is
            // how a paired phone showed up as "This device (Mac)".
            'name' => $peer->deviceName !== null && $peer->deviceName !== ''
                ? $peer->deviceName
                : Lang::get('sync::devices.peer_default_name'),
            'ed25519_public_key_hex' => $peer->ed25519PubHex,
            'x25519_public_key_hex' => $peer->x25519PubHex,
            'safety_number_words' => $safetyWords,
            'is_self' => 0,
            'paired_at' => $now,
            'confirmed_at' => $now,
            // Where the offer was fetched from, so the first sync dial has an
            // address without paying a browse — the only address an iPhone
            // gets at all while its multicast entitlement is ungranted.
            'last_lan_host' => $peer->lanHost,
            'last_lan_port' => $peer->lanPort,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function asInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
