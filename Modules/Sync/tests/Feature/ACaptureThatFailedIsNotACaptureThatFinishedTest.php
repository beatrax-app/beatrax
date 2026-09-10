<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\OpLog\BackfillProgress;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

uses(RefreshDatabase::class);

// Measured on two paired devices: 8,481 op-log records copied, quarantine 0 on
// both, and seventeen tax tags on neither. The walk died on a lock three
// seconds in, the catch stamped completed_at, and the two tables the insertion
// order had not reached yet were never captured by anything, ever again.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md#a-failed-slice-leaves-the-walk-owed
 */

// Last but one in CoveredTableOrder::insertionOrder(), which is the half of the
// order a failure anywhere in the walk used to take with it. Every foreign key
// it names is nullable, so it can stand alone in a fixture.
const CAPTURE_GAP_TAIL_TABLE = 'anomaly_suppression_rules';

function captureGapUser(): User
{
    /** @var Session $session */
    $session = app(Session::class);

    $user = User::query()->create([
        'username' => 'capture-gap-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    app(GdkKeyringService::class)->generateAndPersist($user->id, $session);

    return $user;
}

function captureGapBindWriter(int $userId): void
{
    $keypair = sodium_crypto_sign_keypair();

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'capture-gap-device',
        'name' => 'Capture gap fixture',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
        'x25519_public_key_hex' => str_repeat('00', 32),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => '2026-09-10T00:00:00+00:00',
        'confirmed_at' => '2026-09-10T00:00:00+00:00',
        'last_seen_at' => null,
        'created_at' => '2026-09-10T00:00:00+00:00',
        'updated_at' => '2026-09-10T00:00:00+00:00',
    ]);

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'capture-gap-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

// Ciphertext from a keyring this user does not hold. The codec blanks it on
// read, and shipping the blank would erase the name on every peer, so the walk
// refuses the row — permanently, which is what makes it the hard case.
function captureGapUnreadableCounterparty(int $userId): void
{
    $stranger = captureGapUser();

    $foreign = app(SensitiveColumnCodec::class)
        ->encryptValue('counterparties', 'display_name', 'ALBERT HEIJN', $stranger->id, app(Session::class));

    app(DatabaseManager::class)->connection()->table('counterparties')->insert([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'capture-gap-unreadable',
        'display_name' => $foreign,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);
}

function captureGapTailRows(int $userId, int $count): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    for ($i = 1; $i <= $count; $i++) {
        $db->connection()->table(CAPTURE_GAP_TAIL_TABLE)->insert([
            'user_id' => $userId,
            'detector' => 'large',
            'direction' => 'expense',
            'amount_band_low_minor' => 1000 * $i,
            'amount_band_high_minor' => 2000 * $i,
            'currency' => 'EUR',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
    }
}

function captureGapCapturedPks(int $userId, string $table): int
{
    return app(DatabaseManager::class)->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->where('op_type', OpType::CreateRow->value)
        ->distinct()
        ->count('pk');
}

function captureGapState(int $userId): object
{
    $row = app(DatabaseManager::class)->connection()->table('sync_backfill_state')
        ->where('user_id', $userId)
        ->first(['completed_at', 'failed_slices', 'cursor_table']);

    expect($row)->not->toBeNull('the capture never opened a walk at all');

    return (object) $row;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10 09:00:00');

    $this->recorded = [];

    $this->app->instance(LoggerInterface::class, new class($this->recorded) implements LoggerInterface
    {
        use LoggerTrait;

        /**
         * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $recorded
         */
        public function __construct(public array &$recorded) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->recorded[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('places the tail table last, which is what a failure anywhere in the walk used to cost', function (): void {
    $order = app(CoveredTableOrder::class)->insertionOrder();

    // Not decoration: a leaf whose parents settle late lands at the end of a
    // topological order, so the tables most exposed to an abandoned walk are
    // exactly the ones nothing else would notice missing.
    expect(array_slice($order, -2))->toContain(CAPTURE_GAP_TAIL_TABLE);
});

it('captures the tables that come after the one it could not read', function (): void {
    $user = captureGapUser();
    captureGapUnreadableCounterparty($user->id);
    captureGapTailRows($user->id, 2);
    captureGapBindWriter($user->id);

    app(PreSyncHistoryCapture::class)->capture($user->id);

    // counterparties sits eleventh of thirty-nine. One throw there retired the
    // whole order, and the twenty-eight tables below it were never walked.
    expect(captureGapCapturedPks($user->id, CAPTURE_GAP_TAIL_TABLE))->toBe(2)
        ->and(captureGapCapturedPks($user->id, 'counterparties'))->toBe(0);
});

it('leaves the walk owed when a table would not walk, rather than stamping it complete', function (): void {
    $user = captureGapUser();
    captureGapUnreadableCounterparty($user->id);
    captureGapBindWriter($user->id);

    app(PreSyncHistoryCapture::class)->capture($user->id);

    $state = captureGapState($user->id);

    expect($state->completed_at)->toBeNull()
        ->and((int) $state->failed_slices)->toBe(1)
        // Rewound, because the table it missed can sit anywhere in the order
        // and a cursor pointing past it would never reach it again.
        ->and($state->cursor_table)->toBeNull();
});

it('names the table it left behind instead of passing in silence', function (): void {
    $user = captureGapUser();
    captureGapUnreadableCounterparty($user->id);
    captureGapBindWriter($user->id);

    app(PreSyncHistoryCapture::class)->capture($user->id);

    $reported = array_values(array_filter(
        $this->recorded,
        static fn (array $line): bool => $line['level'] === 'error'
            && str_contains($line['message'], 'rows no peer will ever be told about'),
    ));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]['context']['uncovered'])->toHaveKey('counterparties')
        ->and($reported[0]['context']['uncovered']['counterparties'])->toBe(['rows' => 1, 'captured' => 0]);
});

it('says what it covered when nothing was left behind', function (): void {
    $user = captureGapUser();
    captureGapTailRows($user->id, 2);
    captureGapBindWriter($user->id);

    app(PreSyncHistoryCapture::class)->capture($user->id);

    $covered = array_values(array_filter(
        $this->recorded,
        static fn (array $line): bool => str_contains($line['message'], 'every covered table is in the log'),
    ));

    // The positive control. A clean walk that logs nothing is indistinguishable
    // from a walk that never ran, which is the reading that let a hole through.
    expect($covered)->toHaveCount(1)
        ->and($covered[0]['context']['uncovered'])->toBe(0)
        ->and($covered[0]['context']['tables'])->toBeGreaterThan(30)
        ->and(captureGapState($user->id)->completed_at)->not->toBeNull();
});

it('retries a chunk the engine refused for a lock instead of losing the table', function (): void {
    $user = captureGapUser();
    captureGapTailRows($user->id, 2);
    captureGapBindWriter($user->id);

    $armed = true;

    app(DatabaseManager::class)->connection()->beforeExecuting(
        function (string $query) use (&$armed): void {
            if ($armed && str_contains($query, 'insert into "op_log_entries"')) {
                $armed = false;

                throw new PDOException('database is locked');
            }
        },
    );

    app(PreSyncHistoryCapture::class)->capture($user->id);

    expect($armed)->toBeFalse('the lock never fired, so this proves nothing')
        ->and(captureGapCapturedPks($user->id, CAPTURE_GAP_TAIL_TABLE))->toBe(2)
        ->and(captureGapState($user->id)->completed_at)->not->toBeNull();
});

it('re-walks a table a lock cost it, once the lock is gone', function (): void {
    $user = captureGapUser();
    captureGapTailRows($user->id, 2);
    captureGapBindWriter($user->id);

    $blocked = true;

    // Every attempt at this one table, so the chunk retry is exhausted and the
    // table is genuinely lost to the slice — the shape the relay's drains put
    // the desktop in while the walk was reaching the end of the order.
    app(DatabaseManager::class)->connection()->beforeExecuting(
        function (string $query, array $bindings) use (&$blocked): void {
            if ($blocked && str_contains($query, 'insert into "op_log_entries"')
                && in_array(CAPTURE_GAP_TAIL_TABLE, $bindings, true)) {
                throw new PDOException('database is locked');
            }
        },
    );

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);
    $capture->capture($user->id);

    expect(captureGapCapturedPks($user->id, CAPTURE_GAP_TAIL_TABLE))->toBe(0)
        ->and(captureGapState($user->id)->completed_at)->toBeNull();

    $blocked = false;
    $capture->resume($user->id);

    expect(captureGapCapturedPks($user->id, CAPTURE_GAP_TAIL_TABLE))->toBe(2)
        ->and(captureGapState($user->id)->completed_at)->not->toBeNull();
});

it('stops working a walk nothing can advance, without ever calling it finished', function (): void {
    $user = captureGapUser();
    captureGapUnreadableCounterparty($user->id);
    captureGapBindWriter($user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);
    $capture->capture($user->id);

    for ($slice = 0; $slice < BackfillProgress::MAX_FAILED_SLICES; $slice++) {
        $capture->resume($user->id);
    }

    // A driver on every request tail cannot spend the life of the install on a
    // row no keyring opens. Stalled is not complete: the next pairing reopens
    // it, and completed_at never claims a walk that did not cover the ledger.
    expect(app(BackfillProgress::class)->isOpen($user->id))->toBeFalse()
        ->and(captureGapState($user->id)->completed_at)->toBeNull()
        ->and((int) captureGapState($user->id)->failed_slices)->toBe(BackfillProgress::MAX_FAILED_SLICES);
});

// The bound the stall exists to be is a bound on the request tail, and the
// request tail was the thing clearing it: DeliversOwedEpochs opens a capture on
// every tick it is allowed, and opening a walk reset the count that had stopped
// it. Eight fruitless slices apart, that made the ceiling a ceiling nothing
// ever reached.

function captureGapStall(int $userId): void
{
    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);
    $capture->capture($userId);

    for ($slice = 0; $slice < BackfillProgress::MAX_FAILED_SLICES; $slice++) {
        $capture->resume($userId);
    }
}

it('leaves a stalled walk stalled however often the request tail comes back', function (): void {
    $user = captureGapUser();
    captureGapUnreadableCounterparty($user->id);
    captureGapBindWriter($user->id);

    captureGapStall((int) $user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    for ($tick = 0; $tick < 5; $tick++) {
        $capture->owe((int) $user->id);
    }

    expect(app(BackfillProgress::class)->isOpen((int) $user->id))->toBeFalse()
        ->and((int) captureGapState((int) $user->id)->failed_slices)->toBe(BackfillProgress::MAX_FAILED_SLICES)
        ->and(captureGapState((int) $user->id)->completed_at)->toBeNull();
});

it('works the walk again when the reader asks for one', function (): void {
    $user = captureGapUser();
    captureGapUnreadableCounterparty($user->id);
    captureGapBindWriter($user->id);

    captureGapStall((int) $user->id);

    app(PreSyncHistoryCapture::class)->capture((int) $user->id);

    // One slice of the reopened walk runs inside capture(), and it is fruitless
    // for the same reason as the eight before it -- so the count stands at one,
    // not at nothing. What matters is that it is no longer at the ceiling.
    expect((int) captureGapState((int) $user->id)->failed_slices)->toBeLessThan(BackfillProgress::MAX_FAILED_SLICES);
});
