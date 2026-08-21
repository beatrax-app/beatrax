<?php

declare(strict_types=1);

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

// user_id is a per-device autoincrement, so an entry accepted from a peer is
// re-scoped onto the local user before it is stored — and a v1 signature covers
// user_id. Live sync verifies before re-scoping and passed; the rebuild then
// re-verified the stored rows and quarantined every one of them as forged.

function rescopedSignedEntry(int $originUserId, string $secretKey): OpLogEntry
{
    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 251,
        field: 'note',
        value: '"lunch"',
        hlcL: 1786552276326,
        hlcC: 0,
        deviceId: 'origin-device',
        opType: OpType::CreateRow,
        signature: '',
        userId: $originUserId,
    );

    return new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        // Signed with the LEGACY payload — what a device on the previous
        // signing version actually put on the wire.
        signature: sodium_bin2hex(sodium_crypto_sign_detached($entry->legacySigningPayload(), $secretKey)),
        userId: $entry->userId,
    );
}

it('keeps a legacy-signed entry verifiable after it is re-scoped onto the local user', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);

    $received = rescopedSignedEntry(3, $secret);

    $verifies = static fn (OpLogEntry $entry): bool => array_reduce(
        $entry->signatureCandidates(),
        static fn (bool $carry, string $payload): bool => $carry || sodium_crypto_sign_verify_detached(
            sodium_hex2bin($entry->signature),
            $payload,
            $public,
        ),
        false,
    );

    expect($verifies($received))->toBeTrue('the entry must verify as received');

    // What the verifier persists: the same entry re-scoped onto the local user
    // so local queries can find it.
    $stored = $received->withUserId(1);

    expect($stored->userId)->toBe(1, 'the row is scoped locally')
        ->and($stored->originUserId)->toBe(3, 'but remembers what was signed')
        ->and($verifies($stored))->toBeTrue('and must still verify — this is the rebuild path');
});

it('does not invent an origin for an entry that was never re-scoped', function (): void {
    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 1,
        field: 'note',
        value: null,
        hlcL: 1,
        hlcC: 0,
        deviceId: 'local-device',
        opType: OpType::Set,
        signature: '',
        userId: 7,
    );

    expect($entry->originUserId)->toBeNull()
        ->and($entry->withUserId(7))->toBe($entry, 'a no-op re-scope must not manufacture an origin');
});
