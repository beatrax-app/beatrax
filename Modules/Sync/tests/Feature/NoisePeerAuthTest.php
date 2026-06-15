<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * NoisePeerAuthTest — XPORT-01: confirmed-device authentication gate.
 *
 * RED until Wave 2 ships NoiseHandshakeState and the post-handshake
 * peer-key validator in Modules\Sync\Internal\Transport\Noise\.
 *
 * After a Noise IK or XX handshake completes, the responder's revealed
 * static X25519 public key MUST be compared against
 * DeviceRegistryService::deviceX25519Keys() (confirmed-only, user-scoped).
 * An unconfirmed device (safety-number not yet verified) MUST NOT be admitted
 * to a Noise session — even if the handshake cryptography succeeds.
 *
 * Trust anchor: Phase 12 device_registry with confirmed_at IS NOT NULL (T-13-01).
 * This is the Noise-level analog of the op-log's deviceKeys() replayer gate.
 *
 * Two invariants:
 *   I-AUTH-1: A confirmed peer's X25519 static key is admitted (happy path).
 *   I-AUTH-2: An unconfirmed peer's X25519 static key is rejected, session
 *             closed (T-13-01 / RESEARCH Pattern 2 / Pitfall 2 guard).
 */

it('admits a Noise session whose static key matches a confirmed device_registry entry', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: NoiseHandshakeState must not exist yet — implement in Wave 2.'
    );
})->todo('Wave 2: after handshake, DeviceRegistryService::deviceX25519Keys() contains peer static key → session admitted');

it('rejects a Noise session whose static key is not in device_registry (unconfirmed device)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: peer static key absent from deviceX25519Keys() → session rejected, connection closed, no ops replayed');

it('rejects a Noise session whose static key belongs to a different user (cross-user scope guard)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: peer static key in device_registry for user B is not admitted when validating for user A (T-13-01 user scope)');

it('rejects a Noise session after static key is removed from device_registry (revocation)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: once deviceX25519Keys() no longer returns the peer key, new sessions from that device are rejected');
