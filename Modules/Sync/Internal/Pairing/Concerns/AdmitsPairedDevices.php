<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing\Concerns;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
trait AdmitsPairedDevices
{
    // Idempotent: a re-confirm updates the existing (user_id, device_id) row
    // rather than inserting a duplicate (the composite key is UNIQUE).
    private function admitResponderDevice(\stdClass $row, int $userId): void
    {
        $deviceId = is_string($row->responder_device_id) ? $row->responder_device_id : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;
        $responderKx = is_string($row->responder_x25519_pub_hex) ? $row->responder_x25519_pub_hex : null;
        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;

        if ($deviceId === null || $responderEd === null || $responderKx === null || $initiatorEd === null) {
            return;
        }

        // Never admit a responder whose device_id collides with this user's
        // own self device — a crafted responder_device_id equal to the
        // self-row id would otherwise overwrite the local identity's own keys.
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (is_string($selfDeviceId) && hash_equals($selfDeviceId, $deviceId)) {
            return;
        }

        $safetyWords = implode(' ', $this->safetyNumberDeriver->deriveWords($initiatorEd, $responderEd));
        $now = $this->clock->now()->toIso8601String();

        $registry = $this->db->connection()->table('device_registry');

        // Scope the lookup/update to NON-self rows — defense-in-depth so an
        // admit can never mutate the local self-row even if the collision
        // guard above is ever bypassed.
        $existing = $registry
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
                    'ed25519_public_key_hex' => $responderEd,
                    'x25519_public_key_hex' => $responderKx,
                    'safety_number_words' => $safetyWords,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $this->deviceNameDetector->detect(),
            'ed25519_public_key_hex' => $responderEd,
            'x25519_public_key_hex' => $responderKx,
            'safety_number_words' => $safetyWords,
            'is_self' => 0,
            'paired_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // The counterpart of admitResponderDevice() (same shape). Only ever
    // called from finalizeIfBothConfirmed()'s initiator_seeded_at branch —
    // never for a placeholder issue()-created row (see @link).
    private function admitInitiatorDevice(\stdClass $row, int $userId): void
    {
        $deviceId = is_string($row->initiator_device_id) ? $row->initiator_device_id : null;
        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $initiatorKx = is_string($row->initiator_x25519_pub_hex) ? $row->initiator_x25519_pub_hex : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($deviceId === null || $initiatorEd === null || $initiatorKx === null || $responderEd === null) {
            return;
        }

        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (is_string($selfDeviceId) && hash_equals($selfDeviceId, $deviceId)) {
            return;
        }

        $safetyWords = implode(' ', $this->safetyNumberDeriver->deriveWords($initiatorEd, $responderEd));
        $now = $this->clock->now()->toIso8601String();

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
                    'ed25519_public_key_hex' => $initiatorEd,
                    'x25519_public_key_hex' => $initiatorKx,
                    'safety_number_words' => $safetyWords,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $this->deviceNameDetector->detect(),
            'ed25519_public_key_hex' => $initiatorEd,
            'x25519_public_key_hex' => $initiatorKx,
            'safety_number_words' => $safetyWords,
            'is_self' => 0,
            'paired_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
