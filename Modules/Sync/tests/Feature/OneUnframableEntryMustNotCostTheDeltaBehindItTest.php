<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\CatchUpDelta;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpCursors;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// A note with no maxlength and no validation reaches 48,096 characters, its
// sealed column reaches 64 KB, and the entry carrying it can never be framed.
// packIntoFrames() handed it to encode() anyway, so the OverflowException took
// down the whole owed delta and the per-author cursor never moved: that device
// could never push that row — or anything behind it — to any peer again.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#one-entry-that-can-never-be-framed
 */
const UNFRAMABLE_DEVICE = 'unframable-author-device';

function unframableUser(): User
{
    return User::query()->create([
        'username' => 'unframable-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function unframableRegisterAuthor(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => UNFRAMABLE_DEVICE,
        'name' => UNFRAMABLE_DEVICE,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-28T10:00:00Z',
        'confirmed_at' => '2026-08-28T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-08-28T10:00:00Z',
        'updated_at' => '2026-08-28T10:00:00Z',
    ]);
}

function unframableEntry(DatabaseManager $db, int $userId, int $hlcL, string $pk, string $value): void
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'origin_user_id' => null,
        'table_name' => 'transactions',
        'pk' => $pk,
        'field' => 'note',
        'value' => $value,
        'hlc_l' => $hlcL,
        'hlc_c' => 0,
        'device_id' => UNFRAMABLE_DEVICE,
        'op_type' => OpType::Set->value,
        'signature' => str_repeat('s', 88),
        'gdk_epoch' => null,
        'recorded_at' => '2026-08-28T10:00:00Z',
    ]);
}

function unframablePksIn(CatchUpDelta $delta): array
{
    $framer = new TransportFramer;
    $pks = [];

    foreach ($delta as $frame) {
        foreach ($framer->decode($frame) as $entry) {
            $pks[] = (string) $entry->pk;
        }
    }

    return $pks;
}

it('delivers every framable entry when one entry in the middle can never be framed', function (): void {
    $db = app(DatabaseManager::class);
    $user = unframableUser();
    unframableRegisterAuthor($db, $user->id);

    unframableEntry($db, $user->id, 1000, 'before', 'a short note');
    unframableEntry($db, $user->id, 1001, 'oversized', str_repeat('v', 70000));
    unframableEntry($db, $user->id, 1002, 'after', 'another short note');

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    $frames = $exchanger->opsAfterWatermark($user->id, PeerCatchUpCursors::none());

    // Not "fewer frames" — the two rows either side of the unsyncable one have
    // to arrive, because a cursor that never advances re-fails identically on
    // every reconnect for as long as that row exists.
    expect(unframablePksIn($frames))->toBe(['before', 'after']);
});

it('answers an empty delta rather than throwing when the only owed entry is unframable', function (): void {
    $db = app(DatabaseManager::class);
    $user = unframableUser();
    unframableRegisterAuthor($db, $user->id);

    unframableEntry($db, $user->id, 1000, 'oversized', str_repeat('v', 70000));

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    expect($exchanger->opsAfterWatermark($user->id, PeerCatchUpCursors::none()))->toHaveCount(0);
});

it('names the row it withheld, at error level, rather than leaving a clean-looking sync', function (): void {
    $db = app(DatabaseManager::class);
    $user = unframableUser();
    unframableRegisterAuthor($db, $user->id);

    unframableEntry($db, $user->id, 1000, 'oversized', str_repeat('v', 70000));

    $recorder = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'context' => $context];
        }
    };

    (new PeerCatchUpExchanger($db, new TransportFramer, $recorder))
        ->opsAfterWatermark($user->id, PeerCatchUpCursors::none());

    expect($recorder->records)->toHaveCount(1)
        ->and($recorder->records[0]['level'])->toBe('error')
        ->and($recorder->records[0]['context']['table'])->toBe('transactions')
        ->and($recorder->records[0]['context']['pk'])->toBe('oversized')
        ->and($recorder->records[0]['context']['field'])->toBe('note')
        ->and($recorder->records[0]['context']['device_id'])->toBe(UNFRAMABLE_DEVICE)
        ->and($recorder->records[0]['context']['entry_bytes'])->toBeGreaterThan(TransportFramer::MAX_PAYLOAD_BYTES)
        // The value is ledger content and never travels into a log line.
        ->and($recorder->records[0]['context'])->not->toHaveKey('value');
});
