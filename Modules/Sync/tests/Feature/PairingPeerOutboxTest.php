<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;

uses(RefreshDatabase::class);

// Only one side of a pairing listens: the desktop runs the daemon, a phone runs
// no server and advertises nothing. So a frame addressed to a phone cannot be
// pushed — it waits here until the phone asks for it.

function outbox(): PairingPeerOutbox
{
    return app(PairingPeerOutbox::class);
}

/**
 * @return array<string, mixed>
 */
function confirmFrame(): array
{
    return [
        'type' => 'PAIR_CONFIRM',
        'token_hash' => str_repeat('d', 64),
        'confirming_device_id' => '11111111-2222-4333-8444-555555555555',
        'peer_device_id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
        'sig_hex' => str_repeat('f', 128),
    ];
}

it('hands a queued frame to the device it was addressed to', function (): void {
    outbox()->queueFor('desktop-did', 'phone-did', confirmFrame());

    $taken = outbox()->takeFor('phone-did', 8);

    expect($taken)->toHaveCount(1);
    expect($taken[0]['type'])->toBe('PAIR_CONFIRM');
});

it('hands nothing to a device nothing was addressed to', function (): void {
    outbox()->queueFor('desktop-did', 'phone-did', confirmFrame());

    expect(outbox()->takeFor('someone-else', 8))->toBe([]);
});

// Taken means delivered. A second collection must not replay the same frame, or
// a redelivery would look like a fresh one every three seconds forever.
it('does not hand the same frame over twice', function (): void {
    outbox()->queueFor('desktop-did', 'phone-did', confirmFrame());

    outbox()->takeFor('phone-did', 8);

    expect(outbox()->takeFor('phone-did', 8))->toBe([]);
});

// Epoch wraps wait in this same mailbox for the authenticated sync session to
// carry them. Handing one to whoever asked would mark it delivered and strand
// the peer without that epoch's key — an audit table with no replay path.
it('leaves a frame belonging to another transport where it is', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    outbox()->queueFor('desktop-did', 'phone-did', ['type' => 'GDK_EPOCH_WRAP', 'payload' => 'sealed']);

    expect(outbox()->takeFor('phone-did', 8))->toBe([]);

    // Still pending, not consumed and not marked delivered.
    expect($db->connection()->table('relay_mailbox')
        ->where('recipient_did', 'phone-did')
        ->whereNull('delivered_at')
        ->count())->toBe(1);
});

it('leaves a blob that is not a frame at all where it is', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('relay_mailbox')->insert([
        'sender_did' => 'desktop-did',
        'recipient_did' => 'phone-did',
        'blob' => 'not json',
        'created_at' => '2026-06-15T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-07-15T10:00:00Z',
    ]);

    expect(outbox()->takeFor('phone-did', 8))->toBe([]);
});
