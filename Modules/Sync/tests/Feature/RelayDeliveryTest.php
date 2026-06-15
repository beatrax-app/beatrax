<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;

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

    /** @var stdClass $row */
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

    /** @var stdClass $row */
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

it('RelayClient class exists (Wave 4 implementation landed)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Relay\\RelayClient'))->toBeTrue(
        'Wave 4: RelayClient must exist — implemented in Plan 13-03.'
    );
});

it('RelayMailbox writers produce zero-padded UTC Zulu timestamps (WR-03 GC invariant)', function (): void {
    // The GC compares expires_at lexically, which is only safe when every
    // timestamp is the same zero-padded Zulu form. Exercise deliver() + confirm()
    // and assert the stored timestamps match ^\d{4}-..TZ$ so a future change that
    // drops the Zulu form is caught here.
    $mailbox = new RelayMailbox(
        app(DatabaseManager::class),
        app(Clock::class),
    );

    $mailbox->deliver('device-a', 'device-b', random_bytes(32));

    /** @var stdClass $row */
    $row = DB::table('relay_mailbox')->where('recipient_did', 'device-b')->first();

    $zulu = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
    expect($row->created_at)->toMatch($zulu, 'created_at must be Zulu form');
    expect($row->expires_at)->toMatch($zulu, 'expires_at must be Zulu form');

    $mailbox->confirm((int) $row->id);

    /** @var stdClass $confirmed */
    $confirmed = DB::table('relay_mailbox')->where('id', $row->id)->first();
    expect($confirmed->delivered_at)->toMatch($zulu, 'delivered_at must be Zulu form');
    expect($confirmed->expires_at)->toMatch($zulu, 'reset expires_at must be Zulu form');
});
