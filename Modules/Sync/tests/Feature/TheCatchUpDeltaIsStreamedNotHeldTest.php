<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpCursors;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// The owed delta used to be built as a list: every row fetched, every row
// hydrated into an OpLogEntry, and every 64 KB frame held until the caller
// asked. Three copies of the peer's whole history, alive at once, behind the
// phone's "Sync now" button — 50,000 entries exhausted its 128 MB ceiling
// inside TransportFramer, and a fatal is not a Throwable, so nothing caught it.

const STREAMED_DELTA_DEVICE = 'streamed-delta-author';

function streamedDeltaUser(): int
{
    return (int) DB::table('users')->insertGetId([
        'username' => 'streamed-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function streamedDeltaAuthor(int $userId): void
{
    DB::table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => STREAMED_DELTA_DEVICE,
        'name' => 'Streamed delta fixture',
        'ed25519_public_key_hex' => str_repeat('11', 32),
        'x25519_public_key_hex' => str_repeat('22', 32),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => '2026-06-14T00:00:00+00:00',
        'confirmed_at' => '2026-06-14T00:00:00+00:00',
        'created_at' => '2026-06-14T00:00:00+00:00',
        'updated_at' => '2026-06-14T00:00:00+00:00',
    ]);
}

// Written in chunks and never held: a fixture that builds all 20,000 rows in
// one array leaves the allocator holding enough free space to hide exactly the
// growth this file measures.
function streamedDeltaOps(int $userId, int $count, int $firstHlc = 1_000_000): void
{
    $chunk = [];

    for ($i = 0; $i < $count; $i++) {
        $chunk[] = [
            'user_id' => $userId,
            'device_id' => STREAMED_DELTA_DEVICE,
            'table_name' => 'transactions',
            'pk' => (string) (1000 + intdiv($i, 34)),
            'field' => 'f'.($i % 34),
            'op_type' => 'create_row',
            'value' => '"'.str_repeat('v', 80).'"',
            'hlc_l' => $firstHlc + $i,
            'hlc_c' => 0,
            'signature' => str_repeat('ab', 64),
            'recorded_at' => '2026-07-01 00:00:00',
        ];

        if (count($chunk) === 500) {
            DB::table('op_log_entries')->insert($chunk);
            $chunk = [];
        }
    }

    if ($chunk !== []) {
        DB::table('op_log_entries')->insert($chunk);
    }
}

it('streams a twenty-thousand entry delta without ever holding it', function (): void {
    $userId = streamedDeltaUser();
    streamedDeltaAuthor($userId);
    streamedDeltaOps($userId, 20_000);

    $exchanger = new PeerCatchUpExchanger(app(DatabaseManager::class), new TransportFramer, new NullLogger);

    gc_collect_cycles();
    $before = memory_get_usage(true);

    $delta = $exchanger->opsAfterWatermark($userId, PeerCatchUpCursors::none());

    $streamed = 0;
    $bytes = 0;
    foreach ($delta as $frame) {
        $streamed++;
        $bytes += strlen($frame);
    }

    $grew = memory_get_usage(true) - $before;

    // The whole delta is roughly 7.8 MB of frames on the wire and cost 40 MB
    // resident to produce as a list. Twelve is above anything a bounded pass
    // needs and far below what one copy of this delta costs.
    expect($streamed)->toBeGreaterThan(100)
        ->and($bytes)->toBeGreaterThan(5_000_000)
        ->and($grew)->toBeLessThan(12 * 1024 * 1024);
});

it('declares exactly as many frames as it goes on to send', function (): void {
    $userId = streamedDeltaUser();
    streamedDeltaAuthor($userId);
    streamedDeltaOps($userId, 3_000);

    $exchanger = new PeerCatchUpExchanger(app(DatabaseManager::class), new TransportFramer, new NullLogger);

    $delta = $exchanger->opsAfterWatermark($userId, PeerCatchUpCursors::none());

    // The receiver reads exactly frame_count frames off the wire, so a stream
    // that disagrees with the number already declared desynchronises the
    // protocol rather than merely sending the wrong amount.
    expect($exchanger->buildResponse($delta)['frame_count'])->toBe(iterator_count($delta->getIterator()))
        ->and(count($delta))->toBeGreaterThan(1);
});

it('sends the delta it counted, not the one that grew while it was counting', function (): void {
    $userId = streamedDeltaUser();
    streamedDeltaAuthor($userId);
    streamedDeltaOps($userId, 3_000);

    $exchanger = new PeerCatchUpExchanger(app(DatabaseManager::class), new TransportFramer, new NullLogger);

    $delta = $exchanger->opsAfterWatermark($userId, PeerCatchUpCursors::none());
    $declared = count($delta);

    // A local write between the count and the send is ordinary — this device
    // keeps working while a peer drains it — and it must not add a frame the
    // peer was never told to read.
    streamedDeltaOps($userId, 3_000, 9_000_000);

    expect(iterator_count($delta->getIterator()))->toBe($declared);
});

it('names an unframable entry once, not once per pass over the delta', function (): void {
    $userId = streamedDeltaUser();
    streamedDeltaAuthor($userId);
    streamedDeltaOps($userId, 10);

    DB::table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => STREAMED_DELTA_DEVICE,
        'table_name' => 'transactions',
        'pk' => 'oversized',
        'field' => 'note',
        'op_type' => 'set',
        'value' => '"'.str_repeat('v', 70_000).'"',
        'hlc_l' => 2_000_000,
        'hlc_c' => 0,
        'signature' => str_repeat('ab', 64),
        'recorded_at' => '2026-07-01 00:00:00',
    ]);

    $recorder = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $levels = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->levels[] = (string) $level;
        }
    };

    $delta = (new PeerCatchUpExchanger(app(DatabaseManager::class), new TransportFramer, $recorder))
        ->opsAfterWatermark($userId, PeerCatchUpCursors::none());

    iterator_count($delta->getIterator());
    iterator_count($delta->getIterator());

    expect($recorder->levels)->toBe(['error']);
});
