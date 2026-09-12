<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Services\ImportSyncCapture;

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

// The one fact oweABackfill() reads before opening a walk: a device that never
// enabled sync owes no peer anything, and the key-file is how it says so.
function halfCaptureIdentityPath(int $userId): string
{
    return UserDataPathService::appPath("sync/identity/{$userId}.enc");
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
