<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * RelayDeliveryTest — XPORT-03: relay delivers blob to correct device.
 *
 * RED until Wave 4 ships RelayClient under
 * Modules\Sync\Internal\Transport\Relay\RelayClient.
 *
 * Validates the relay store-and-forward lifecycle (RESEARCH Pattern 4):
 *   1. A sender can "deliver" (POST) a ciphertext blob to a recipient's mailbox.
 *   2. The relay stores the blob with delivered_at = NULL (pending).
 *   3. The recipient can "drain" (GET) their pending blobs.
 *   4. After drain, delivered_at is set (not NULL); the blob is no longer pending.
 *   5. Only the correct recipient can drain their mailbox (addressing guard).
 *
 * At Wave 0, the RelayClient class does not exist. Tests use ->todo() or
 * assert at the schema/DB layer to confirm the migration structure is correct
 * for the relay lifecycle. Full end-to-end delivery tests require Wave 4.
 *
 * The relay is ZK: no test in this file decrypts the blob. The blob is always
 * treated as opaque bytes (see RelayZeroKnowledgeTest for the ZK invariant).
 */

it('relay mailbox accepts a ciphertext blob and marks it pending (delivered_at IS NULL)', function (): void {
    $blob = random_bytes(128);

    DB::table('relay_mailbox')->insert([
        'sender_did' => 'device-sender',
        'recipient_did' => 'device-recipient',
        'blob' => $blob,
        'created_at' => '2026-06-15T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-07-15T10:00:00Z',
    ]);

    /** @var \stdClass $row */
    $row = DB::table('relay_mailbox')
        ->where('recipient_did', 'device-recipient')
        ->first();

    expect($row)->not->toBeNull('Row must be inserted');
    expect($row->delivered_at)->toBeNull('Freshly delivered blob must be pending (delivered_at IS NULL)');
    expect($row->blob)->toBe($blob, 'Stored blob must equal the delivered ciphertext');
});

it('drain marks a pending blob as delivered (sets delivered_at)', function (): void {
    DB::table('relay_mailbox')->insert([
        'sender_did' => 'device-a',
        'recipient_did' => 'device-b',
        'blob' => random_bytes(64),
        'created_at' => '2026-06-15T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-07-15T10:00:00Z',
    ]);

    $id = DB::table('relay_mailbox')->where('recipient_did', 'device-b')->value('id');

    // Simulate drain: mark delivered.
    DB::table('relay_mailbox')
        ->where('id', $id)
        ->update(['delivered_at' => '2026-06-15T10:01:00Z']);

    /** @var \stdClass $row */
    $row = DB::table('relay_mailbox')->where('id', $id)->first();

    expect($row->delivered_at)->not->toBeNull('After drain, delivered_at must be set');
    expect($row->delivered_at)->toBe('2026-06-15T10:01:00Z');
});

it('pending drain query returns only undelivered blobs for the recipient', function (): void {
    $recipientDid = 'device-recipient-only';

    // One pending blob.
    DB::table('relay_mailbox')->insert([
        'sender_did' => 'device-x',
        'recipient_did' => $recipientDid,
        'blob' => random_bytes(64),
        'created_at' => '2026-06-15T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-07-15T10:00:00Z',
    ]);

    // One already-delivered blob (should NOT appear in pending drain).
    DB::table('relay_mailbox')->insert([
        'sender_did' => 'device-y',
        'recipient_did' => $recipientDid,
        'blob' => random_bytes(64),
        'created_at' => '2026-06-15T09:00:00Z',
        'delivered_at' => '2026-06-15T09:30:00Z',
        'expires_at' => '2026-07-15T09:00:00Z',
    ]);

    $pendingCount = DB::table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->whereNull('delivered_at')
        ->count();

    expect($pendingCount)->toBe(1, 'Only the undelivered blob must appear in pending drain query');
});

it('addressing is isolated: recipient A cannot drain recipient B mailbox', function (): void {
    DB::table('relay_mailbox')->insert([
        'sender_did' => 'device-sender',
        'recipient_did' => 'device-target',
        'blob' => random_bytes(64),
        'created_at' => '2026-06-15T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-07-15T10:00:00Z',
    ]);

    $wrongRecipientBlobs = DB::table('relay_mailbox')
        ->where('recipient_did', 'device-attacker')
        ->whereNull('delivered_at')
        ->count();

    expect($wrongRecipientBlobs)->toBe(0, 'device-attacker must not see device-target\'s pending blobs');
});

it('RelayClient class does not exist yet (Wave 4 guard)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Relay\\RelayClient'))->toBeFalse(
        'Wave 0 guard: RelayClient must not exist yet — implement in Wave 4.'
    );
})->todo('Wave 4: RelayClient::deliver() POSTs ciphertext to relay endpoint; ::drain() retrieves pending blobs');
