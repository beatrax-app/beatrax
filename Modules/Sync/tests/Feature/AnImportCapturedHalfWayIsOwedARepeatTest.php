<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Services\ImportSyncCapture;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

uses(RefreshDatabase::class);

// The capture walks its tables in dependency order and stops at the first one
// that throws, which is right — children announced after their parent failed
// name rows the peer never received. What was missing is what happens next:
// the no-writer arm opens a backfill, this one only wrote a warning, and the
// only other backfill in the product is opened at sync-enable and at pairing.
// So an import that committed here reached a peer never, and the log line was
// the whole record of it.

function halfCaptureUser(): User
{
    return User::query()->create([
        'username' => 'halfcap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function halfCaptureBindWriter(int $userId): void
{
    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'halfcap-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

// What oweABackfill() reads before opening a walk is the standing, not the
// key-file. A device that never enabled sync owes no peer anything; a device
// whose registry says it is a peer owes one whether or not it can sign yet.
function halfCaptureIdentityPath(int $userId): string
{
    return UserDataPathService::appPath("sync/identity/{$userId}.enc");
}

// The half a restored database brings, and the half it cannot: the old
// machine's self row lands and the key-file stays where it was made.
function halfCaptureRestoredSelfRow(int $userId): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'halfcap-restored-device',
        'name' => 'The machine the backup came from',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => '2026-01-01T00:00:00Z',
        'confirmed_at' => '2026-01-01T00:00:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

function halfCaptureEnableSync(int $userId): void
{
    $path = halfCaptureIdentityPath($userId);
    @mkdir(dirname($path), 0o700, true);
    file_put_contents($path, 'sealed identity bytes — only its presence is read here');
}

function halfCaptureTransaction(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Half capture',
        'slug' => 'halfcap-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00HCAP'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/halfcap.csv',
        'sha256' => hash('sha256', 'halfcap-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return (int) $connection->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:01',
        'value_date' => '2026-01-15',
        'amount_minor' => -4500,
        'currency' => 'EUR',
        'settled_amount_minor' => -4500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'halfcap-fp-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'created_at' => '2026-01-15 00:00:00',
        'updated_at' => '2026-01-15 00:00:00',
    ]);
}

// A lock on the one table the order reaches last, so the parents land and the
// rows carrying the money do not — the exact half-capture the warning described
// and nothing repaired.
function halfCaptureRefuseTransactions(): void
{
    app(DatabaseManager::class)->connection()->beforeExecuting(
        function (string $query, array $bindings): void {
            if (str_contains($query, 'insert into "op_log_entries"')
                && in_array('transactions', $bindings, true)) {
                throw new PDOException('database is locked');
            }
        },
    );
}

/**
 * @return list<string>
 */
function halfCaptureTables(int $userId): array
{
    return array_values(array_map(
        static fn (mixed $t): string => (string) $t,
        app(DatabaseManager::class)->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('table_name')
            ->all(),
    ));
}

function halfCaptureOwesAWalk(int $userId): bool
{
    return app(DatabaseManager::class)->connection()->table('sync_backfill_state')
        ->where('user_id', $userId)
        ->whereNull('completed_at')
        ->exists();
}

afterEach(function (): void {
    /** @var int|null $userId */
    $userId = $this->halfCaptureUserId ?? null;
    if ($userId !== null) {
        @unlink(halfCaptureIdentityPath($userId));
    }
});

it('opens a backfill when the capture stops part-way through the order', function (): void {
    $user = halfCaptureUser();
    $this->halfCaptureUserId = (int) $user->id;
    halfCaptureEnableSync((int) $user->id);
    halfCaptureBindWriter((int) $user->id);

    $transactionId = halfCaptureTransaction($user);
    halfCaptureRefuseTransactions();

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);

    $captured = halfCaptureTables((int) $user->id);

    // The half-capture really happened: parents announced, money not.
    expect($captured)->toContain('accounts')
        ->and($captured)->not->toContain('transactions')
        ->and(halfCaptureOwesAWalk((int) $user->id))->toBeTrue();
});

// The reader's import is committed and confirmed before this runs, so nothing
// here may reach them. A throw out of the debt would fail a confirm that landed.
it('never lets the capture failure reach the caller that already committed', function (): void {
    $user = halfCaptureUser();
    $this->halfCaptureUserId = (int) $user->id;
    halfCaptureEnableSync((int) $user->id);
    halfCaptureBindWriter((int) $user->id);

    $transactionId = halfCaptureTransaction($user);
    halfCaptureRefuseTransactions();

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);
})->throwsNoExceptions();

// The positive control. A device that never enabled sync owes no peer anything,
// and opening a walk for it would put a permanent debt on a single-device
// install that can never discharge it.
it('opens no backfill for a device that never enabled sync', function (): void {
    $user = halfCaptureUser();
    halfCaptureBindWriter((int) $user->id);

    $transactionId = halfCaptureTransaction($user);
    halfCaptureRefuseTransactions();

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);

    expect(halfCaptureTables((int) $user->id))->not->toContain('transactions')
        ->and(halfCaptureOwesAWalk((int) $user->id))->toBeFalse();
});

// The same premise the capture sink held, in the one other place that held it:
// asked of the key-file alone, a restored database answers "never enabled" and
// an import committed on it is owed nothing and reaches no peer. Its registry
// says it is a peer, and a peer already holding these rows is exactly the case
// a walk is for.
it('opens a backfill for a restored device whose key-file never travelled', function (): void {
    $user = halfCaptureUser();
    halfCaptureRestoredSelfRow((int) $user->id);
    halfCaptureBindWriter((int) $user->id);

    $transactionId = halfCaptureTransaction($user);
    halfCaptureRefuseTransactions();

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);

    expect(halfCaptureTables((int) $user->id))->not->toContain('transactions')
        ->and(halfCaptureOwesAWalk((int) $user->id))->toBeTrue();
});

// A capture that finished owes nothing: opening a whole-database walk on every
// successful import would be the backfill running forever.
it('opens no backfill when the capture reaches every table', function (): void {
    $user = halfCaptureUser();
    $this->halfCaptureUserId = (int) $user->id;
    halfCaptureEnableSync((int) $user->id);
    halfCaptureBindWriter((int) $user->id);

    $transactionId = halfCaptureTransaction($user);

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);

    expect(halfCaptureTables((int) $user->id))->toContain('transactions')
        ->and(halfCaptureOwesAWalk((int) $user->id))->toBeFalse();
});

// The debt itself can fail, and it is reached from inside a catch on the tail
// of a commit the reader has already been told about. A throw here would fail
// an import that landed, so the line is what is left when even the debt cannot
// be filed — and it has to be an error, not a pass in silence.
it('reports rather than throws when the backfill it owes cannot be opened', function (): void {
    $user = halfCaptureUser();
    $this->halfCaptureUserId = (int) $user->id;
    halfCaptureEnableSync((int) $user->id);
    halfCaptureBindWriter((int) $user->id);

    $recorded = [];
    $this->app->instance(LoggerInterface::class, new class($recorded) implements LoggerInterface
    {
        use LoggerTrait;

        /**
         * @param  list<array{level: string, message: string}>  $recorded
         */
        public function __construct(public array &$recorded) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->recorded[] = ['level' => is_string($level) ? $level : (string) $level, 'message' => (string) $message];
        }
    });

    // The one collaborator the debt reaches for, refusing to resolve at all.
    $this->app->bind(DeviceIdentityLoader::class, static function (): DeviceIdentityLoader {
        throw new RuntimeException('the identity loader could not be built');
    });

    $transactionId = halfCaptureTransaction($user);
    halfCaptureRefuseTransactions();

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);

    $reported = array_values(array_filter(
        $recorded,
        static fn (array $line): bool => $line['level'] === 'error'
            && str_contains($line['message'], 'no pass will carry these rows to a peer'),
    ));

    expect($reported)->toHaveCount(1)
        ->and(halfCaptureOwesAWalk((int) $user->id))->toBeFalse();
});
