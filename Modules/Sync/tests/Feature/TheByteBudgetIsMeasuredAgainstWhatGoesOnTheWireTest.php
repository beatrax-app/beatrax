<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpCursors;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;

uses(RefreshDatabase::class);

// The packer predicted a frame's size from its own copy of the entry encoder,
// and the copy wrote `user_id` as the LOCAL scope while the framer writes the
// id the entry was SIGNED under. A relayed entry carries an origin id the
// local one is shorter than, so every such entry was under-counted and a batch
// packed to just inside the cap was rejected by the encode() that followed.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
const BUDGET_ORIGIN_USER_ID = 999999999999;

const BUDGET_DEVICE = 'budget-origin-device';

function budgetUser(): User
{
    return User::query()->create([
        'username' => 'budget-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Entries relayed from another install: signed under a twelve-digit origin id
// and re-scoped locally to this install's own single-digit one.
function budgetSeedRelayedEntries(DatabaseManager $db, int $userId, int $count): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => BUDGET_DEVICE,
        'name' => BUDGET_DEVICE,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-27T10:00:00Z',
        'confirmed_at' => '2026-08-27T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-08-27T10:00:00Z',
        'updated_at' => '2026-08-27T10:00:00Z',
    ]);

    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'user_id' => $userId,
            'origin_user_id' => BUDGET_ORIGIN_USER_ID,
            'table_name' => 'transactions',
            'pk' => (string) (100000 + $i),
            'field' => 'note',
            'value' => str_repeat('v', 500),
            'hlc_l' => 1000 + $i,
            'hlc_c' => 0,
            'device_id' => BUDGET_DEVICE,
            'op_type' => OpType::Set->value,
            'signature' => str_repeat('s', 88),
            'gdk_epoch' => null,
            'recorded_at' => '2026-08-27T10:00:00Z',
        ];
    }

    $db->connection()->table('op_log_entries')->insert($rows);
}

it('packs frames the framer will actually accept, for entries signed under another install', function (): void {
    $user = budgetUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    budgetSeedRelayedEntries($db, $userId, 200);

    /** @var PeerCatchUpExchanger $exchanger */
    $exchanger = app(PeerCatchUpExchanger::class);

    $frames = $exchanger->opsAfterWatermark($userId, PeerCatchUpCursors::none());

    expect($frames)->not->toHaveCount(0);

    /** @var TransportFramer $framer */
    $framer = app(TransportFramer::class);

    $decoded = 0;

    foreach ($frames as $frame) {
        $entries = $framer->decode($frame);
        $decoded += count($entries);

        expect(strlen($frame) - TransportFramer::LENGTH_PREFIX_BYTES)
            ->toBeLessThanOrEqual(TransportFramer::MAX_PAYLOAD_BYTES);
    }

    expect($decoded)->toBe(200, 'every entry must reach a frame, not be lost to a rejected batch');
});

it('predicts exactly the payload the framer emits, origin id included', function (): void {
    $framer = new TransportFramer;

    $entries = [];

    for ($i = 0; $i < 5; $i++) {
        $entries[] = new OpLogEntry(
            table: 'transactions',
            pk: 100 + $i,
            field: 'note',
            value: str_repeat('v', 40),
            hlcL: 1,
            hlcC: $i,
            deviceId: BUDGET_DEVICE,
            opType: OpType::Set,
            signature: str_repeat('s', 88),
            userId: 1,
            originUserId: BUDGET_ORIGIN_USER_ID,
            gdkEpoch: null,
        );
    }

    expect($framer->payloadBytes($entries))
        ->toBe(strlen($framer->encode($entries)) - TransportFramer::LENGTH_PREFIX_BYTES);
});
