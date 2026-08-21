<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingFrameOutcome;

uses(RefreshDatabase::class);

// One frame, one application path, whichever road carried it. The relay reads
// the outcome as "delete this from the mailbox or keep it for redelivery"; the
// LAN route reads it as "204 or 404". Both readings are wrong if a frame this
// build cannot apply is ever anything but Refused.

function applier(): PairingFrameApplier
{
    return app(PairingFrameApplier::class);
}

it('refuses a frame whose type this build does not know', function (): void {
    expect(applier()->apply(1, ['type' => 'PAIR_SOMETHING_ELSE']))
        ->toBe(PairingFrameOutcome::Refused);
});

it('refuses a frame carrying no type at all', function (): void {
    expect(applier()->apply(1, []))->toBe(PairingFrameOutcome::Refused);
});

it('refuses a frame whose type is not even a string', function (): void {
    expect(applier()->apply(1, ['type' => ['PAIR_CONFIRM']]))
        ->toBe(PairingFrameOutcome::Refused);
});

it('refuses an accept frame missing required fields', function (): void {
    expect(applier()->apply(1, ['type' => 'PAIR_RESPONDER_ACCEPT', 'token_hash' => str_repeat('d', 64)]))
        ->toBe(PairingFrameOutcome::Refused);
});

// The device id is signed over and persisted, so anything that is not the
// UUIDv4 DeviceIdentityService mints is refused before it reaches either.
it('refuses an accept frame whose device id is not a UUIDv4', function (string $deviceId): void {
    $frame = [
        'type' => 'PAIR_RESPONDER_ACCEPT',
        'token_hash' => str_repeat('d', 64),
        'responder_device_id' => $deviceId,
        'responder_ed25519_pub_hex' => str_repeat('a', 64),
        'responder_x25519_pub_hex' => str_repeat('b', 64),
    ];

    expect(applier()->apply(1, $frame))->toBe(PairingFrameOutcome::Refused);
})->with([
    'not a uuid' => ['definitely-not-a-uuid'],
    'empty' => [''],
    // The '|' is the signing-message field delimiter; a device id carrying one
    // could otherwise shift field boundaries in the signed string.
    'carries the signing delimiter' => ['1111|111-2222-4333-8444-555555555555'],
]);

it('refuses a confirm frame missing its signature', function (): void {
    $frame = [
        'type' => 'PAIR_CONFIRM',
        'token_hash' => str_repeat('d', 64),
        'confirming_device_id' => '11111111-2222-4333-8444-555555555555',
        'peer_device_id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
    ];

    expect(applier()->apply(1, $frame))->toBe(PairingFrameOutcome::Refused);
});

// An unknown token is refused, not deferred: Deferred means "come back once the
// human here has confirmed", and there is nothing here to confirm against.
it('refuses a confirm frame for a token this device has never seen', function (): void {
    $frame = [
        'type' => 'PAIR_CONFIRM',
        'token_hash' => str_repeat('e', 64),
        'confirming_device_id' => '11111111-2222-4333-8444-555555555555',
        'peer_device_id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
        'sig_hex' => str_repeat('f', 128),
    ];

    expect(applier()->apply(1, $frame))->toBe(PairingFrameOutcome::Refused);
});
