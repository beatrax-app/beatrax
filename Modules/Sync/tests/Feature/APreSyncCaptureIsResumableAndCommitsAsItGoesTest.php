<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\OpLog\BackfillBudget;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\PreSyncHistoryCapture;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// The capture ran the whole backfill inside ONE synchronous transaction, in a
// web request, under the desktop bundle's 120s ceiling — which a real ledger
// reaches at roughly 4,000 transactions. A max-execution-time fatal is not
// throwable, so the catch never ran, nothing logged, and the transaction died
// holding every entry it had written: 16,184 of them, zero persisted, and no
// resume, so each retry restarted from nothing and died at the same point.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
function resumableCaptureUser(): User
{
    /** @var Session $session */
    $session = app(Session::class);

    $user = User::query()->create([
        'username' => 'resumable-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // Also what satisfies the capture's holds-an-epoch guard: generateAndPersist
    // writes sync_encryption_state.current_epoch as the epoch it minted.
    app(GdkKeyringService::class)->generateAndPersist($user->id, $session);

    return $user;
}

// PreSyncHistoryCapture resolves its writer from the container, and a test
// fixture has no device identity file for it to load one from.
function resumableBindWriter(int $userId): void
{
    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'resumable-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

function resumableCounterparties(int $userId, int $count, ?int $unreadableAt = null): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $unreadable = null;
    if ($unreadableAt !== null) {
        // Ciphertext from a DIFFERENT keyring: structurally valid, opened by no
        // epoch this user holds, so plaintextFields() throws on that one row.
        $stranger = resumableCaptureUser();
        $unreadable = app(SensitiveColumnCodec::class)
            ->encryptValue('counterparties', 'display_name', 'ALBERT HEIJN', $stranger->id, app(Session::class));
    }

    for ($i = 1; $i <= $count; $i++) {
        $db->connection()->table('counterparties')->insert([
            'user_id' => $userId,
            'type' => 'merchant',
            'slug' => 'resumable-'.$userId.'-'.$i,
            'display_name' => $i === $unreadableAt && is_string($unreadable) ? $unreadable : 'Merchant '.$i,
            'created_at' => '2026-08-28 00:00:00',
            'updated_at' => '2026-08-28 00:00:00',
        ]);
    }
}

function resumableCapturedPks(int $userId): int
{
    return app(DatabaseManager::class)->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'counterparties')
        ->where('op_type', OpType::CreateRow->value)
        ->distinct()
        ->count('pk');
}

function resumableWalkIsOpen(int $userId): bool
{
    return app(DatabaseManager::class)->connection()->table('sync_backfill_state')
        ->where('user_id', $userId)
        ->whereNull('completed_at')
        ->exists();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-28 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('keeps every chunk it had already committed when the walk fails part-way through', function (): void {
    $user = resumableCaptureUser();

    // 205 rows: the first chunk of 200 is readable and the failure lands in
    // the second. One transaction around the whole walk discarded both.
    resumableCounterparties($user->id, 205, unreadableAt: 202);
    resumableBindWriter($user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    // Never throws: the caller is a listener and a Livewire tail, neither of
    // which can usefully abort.
    $capture->capture($user->id);

    expect(resumableCapturedPks($user->id))->toBe(200);
});

it('commits what one slice could afford and finishes the rest on the next pass', function (): void {
    $user = resumableCaptureUser();
    resumableCounterparties($user->id, 250);
    resumableBindWriter($user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    // A budget one chunk cannot outlast, standing in for the 120s ceiling.
    $first = $capture->capture($user->id, BackfillBudget::of(app(Clock::class), 2, 3600));

    expect($first)->toBeGreaterThanOrEqual(200)
        ->and(resumableCapturedPks($user->id))->toBe(200)
        // Still owed. Closing here is what would leave a peer on "0 of 0".
        ->and(resumableWalkIsOpen($user->id))->toBeTrue();

    $second = $capture->resume($user->id);

    expect($second)->toBe(50)
        ->and(resumableCapturedPks($user->id))->toBe(250)
        ->and(resumableWalkIsOpen($user->id))->toBeFalse();
});

it('resumes from the cursor rather than re-walking what the last slice wrote', function (): void {
    $user = resumableCaptureUser();
    resumableCounterparties($user->id, 250);
    resumableBindWriter($user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    $capture->capture($user->id, BackfillBudget::of(app(Clock::class), 2, 3600));

    $cursor = app(DatabaseManager::class)->connection()->table('sync_backfill_state')
        ->where('user_id', $user->id)
        ->first(['cursor_table', 'cursor_pk']);

    expect($cursor->cursor_table)->toBe('counterparties');

    $capture->resume($user->id);

    // Exactly one create op per row: a second pass that re-walked the first
    // 200 rows would still be correct, and would still cost the whole table
    // again on every slice of a ledger big enough to need slicing.
    $ops = app(DatabaseManager::class)->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->where('op_type', OpType::CreateRow->value)
        ->where('field', 'slug')
        ->count();

    expect($ops)->toBe(250);
});

it('leaves the walk owed when the app is locked, rather than retiring it', function (): void {
    $user = resumableCaptureUser();
    resumableCounterparties($user->id, 250);
    resumableBindWriter($user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    $capture->capture($user->id, BackfillBudget::of(app(Clock::class), 2, 3600));

    // Signing needs the app-lock key. A locked session cannot produce a writer
    // at all, which is a reason to come back rather than a verdict on the rows.
    app()->bind(OpLogWriter::class, function (): OpLogWriter {
        throw new LogicException('app-lock engaged');
    });

    expect($capture->resume($user->id))->toBe(0)
        ->and(resumableWalkIsOpen($user->id))->toBeTrue()
        ->and(resumableCapturedPks($user->id))->toBe(200);
});

it('does nothing at all for a user nobody asked for a capture for', function (): void {
    $user = resumableCaptureUser();
    resumableCounterparties($user->id, 5);
    resumableBindWriter($user->id);

    /** @var PreSyncHistoryCapture $capture */
    $capture = app(PreSyncHistoryCapture::class);

    // The resume driver runs on every request, so starting work here would
    // mean every install backfilling itself whether or not sync was enabled.
    expect($capture->resume($user->id))->toBe(0)
        ->and(resumableCapturedPks($user->id))->toBe(0);
});
