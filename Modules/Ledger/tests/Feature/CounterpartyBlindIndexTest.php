<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use Modules\Sync\Public\Services\BlindIndexCodec;

uses(RefreshDatabase::class);

const CBI_MERCHANT = 'Apotheek Zuiderhout';

const CBI_NORMALIZED = 'apotheek zuiderhout';

function cbiUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cbi-'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function cbiAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN cbi',
        'slug' => 'cbi-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
}

function cbiImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cbi.csv',
        'sha256' => hash('sha256', 'cbi-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
}

function cbiSourceRow(Account $account): SourceTransactionDto
{
    return new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse('2026-07-01 12:00:00'),
        postedAt: CarbonImmutable::parse('2026-07-01'),
        valueDate: CarbonImmutable::parse('2026-07-01'),
        ownIban: (string) $account->iban,
        counterpartyIban: 'NL11RABO0123456789',
        counterpartyName: CBI_MERCHANT,
        currency: 'EUR',
        amountMinor: -2450,
        sourceRef: 'ASN-CBI-1',
        description: 'pharmacy',
        rawPayload: [],
        sourceRowIndex: 0,
    );
}

// One pass of the two stages dedup actually runs on: normalise, then classify
// against what is stored. Returns the number of rows this pass inserted.
function cbiImportOnce(User $user, Account $account, ImportRun $run): int
{
    /** @var NormalizeStage $normalize */
    $normalize = app(NormalizeStage::class);
    /** @var FingerprintStage $fingerprint */
    $fingerprint = app(FingerprintStage::class);
    /** @var RecordsTransactions $record */
    $record = app(RecordsTransactions::class);

    $canonical = $normalize->run(cbiSourceRow($account), (int) $account->id, $user, (int) $run->id, 'asn-csv');

    if (! $fingerprint->classify($canonical, $user)->isNew()) {
        return 0;
    }

    return $record([$canonical], $user, captureForSync: false)->inserted;
}

function cbiUnlock(): Session
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    return $session;
}

function cbiStoredKeys(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->pluck('counterparty_normalized')
        ->all();
}

it('stores the merchant name in the clear for a user who never enabled encryption', function (): void {
    $user = cbiUser('plain');
    $account = cbiAccount($user);
    $run = cbiImportRun($user);

    expect(cbiImportOnce($user, $account, $run))->toBe(1);
    expect(cbiStoredKeys($user))->toBe([CBI_NORMALIZED]);
});

it('re-imports the same statement row to a single transaction before encryption', function (): void {
    $user = cbiUser('idem-plain');
    $account = cbiAccount($user);
    $run = cbiImportRun($user);

    expect(cbiImportOnce($user, $account, $run))->toBe(1);
    expect(cbiImportOnce($user, $account, $run))->toBe(0);
    expect(cbiStoredKeys($user))->toHaveCount(1);
});

it('stores a keyed digest instead of the merchant name once encryption is on', function (): void {
    $user = cbiUser('keyed');
    $account = cbiAccount($user);
    $run = cbiImportRun($user);
    $session = cbiUnlock();

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    expect(cbiImportOnce($user, $account, $run))->toBe(1);

    $stored = cbiStoredKeys($user);
    expect($stored)->toHaveCount(1);
    expect($stored[0])->not->toBe(CBI_NORMALIZED);
    expect(BlindIndexCodec::looksDerived((string) $stored[0]))->toBeTrue();
});

it('re-imports the same statement row to a single transaction with encryption on', function (): void {
    $user = cbiUser('idem-keyed');
    $account = cbiAccount($user);
    $run = cbiImportRun($user);
    $session = cbiUnlock();

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    expect(cbiImportOnce($user, $account, $run))->toBe(1);
    expect(cbiImportOnce($user, $account, $run))->toBe(0);
    expect(cbiStoredKeys($user))->toHaveCount(1);
});

// The dangerous case: rows imported in the clear, then encryption enabled, then
// the SAME statement re-imported. The fingerprint is composed over the column
// the sweep rewrites, so the sweep has to rewrite both or this doubles.
it('still recognises a pre-encryption statement row as a duplicate after the enable-time sweep', function (): void {
    $user = cbiUser('sweep');
    $account = cbiAccount($user);
    $run = cbiImportRun($user);

    expect(cbiImportOnce($user, $account, $run))->toBe(1);
    expect(cbiStoredKeys($user))->toBe([CBI_NORMALIZED]);

    $session = cbiUnlock();
    /** @var EncryptionMigrationService $migration */
    $migration = app(EncryptionMigrationService::class);
    $migration->migrate($user, $session);

    $swept = cbiStoredKeys($user);
    expect($swept)->toHaveCount(1);
    expect(BlindIndexCodec::looksDerived((string) $swept[0]))->toBeTrue();

    // Both halves, because two indexes stand behind this. The disposition
    // proves the fingerprint was swept with the key; the row count proves the
    // composite UNIQUE index agrees.
    /** @var NormalizeStage $normalize */
    $normalize = app(NormalizeStage::class);
    /** @var FingerprintStage $fingerprint */
    $fingerprint = app(FingerprintStage::class);
    $fresh = $normalize->run(cbiSourceRow($account), (int) $account->id, $user, (int) $run->id, 'asn-csv');
    expect($fingerprint->classify($fresh, $user)->isNew())->toBeFalse();

    expect(cbiImportOnce($user, $account, $run))->toBe(0);
    expect(cbiStoredKeys($user))->toHaveCount(1);
});

it('rewrites the fingerprint to match the swept key, so it equals what a fresh import composes', function (): void {
    $user = cbiUser('fp');
    $account = cbiAccount($user);
    $run = cbiImportRun($user);

    cbiImportOnce($user, $account, $run);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $before = $db->connection()->table('transactions')->where('user_id', $user->id)->value('fingerprint');

    $session = cbiUnlock();
    /** @var EncryptionMigrationService $migration */
    $migration = app(EncryptionMigrationService::class);
    $migration->migrate($user, $session);

    $after = $db->connection()->table('transactions')->where('user_id', $user->id)->value('fingerprint');
    expect($after)->not->toBe($before);

    /** @var NormalizeStage $normalize */
    $normalize = app(NormalizeStage::class);
    $fresh = $normalize->run(cbiSourceRow($account), (int) $account->id, $user, (int) $run->id, 'asn-csv');

    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);
    expect($composer->compose($fresh))->toBe($after);
});

// Passing the plaintext through here is what would put two forms of one
// merchant inside transactions_fingerprint_uq.
it('refuses to produce a key at all when encryption is on and the app-lock is locked', function (): void {
    $user = cbiUser('locked');
    $session = cbiUnlock();

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var AppLockKeyService $lock */
    $lock = app(AppLockKeyService::class);
    $lock->withhold($session);

    /** @var CounterpartyKey $key */
    $key = app(CounterpartyKey::class);

    expect(fn (): string => $key->forName(CBI_MERCHANT, (int) $user->id))
        ->toThrow(BlindIndexKeyUnavailableException::class);
});

it('leaves the no-counterparty sentinel readable, because it names no merchant', function (): void {
    $user = cbiUser('sentinel');
    $session = cbiUnlock();

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var CounterpartyKey $key */
    $key = app(CounterpartyKey::class);

    expect($key->forName(null, (int) $user->id))->toBe(CounterpartyKey::NONE);
    expect($key->forName('   ', (int) $user->id))->toBe(CounterpartyKey::NONE);
});

// Two paired devices share one keyring key, so the same merchant has to reach
// the same stored value on both or every edit to the row quarantines.
it('derives the same digest under the same key and a different one under another', function (): void {
    $user = cbiUser('peer');
    $session = cbiUnlock();

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var BlindIndexCodec $codec */
    $codec = app(BlindIndexCodec::class);

    $shared = (string) $keyring->blindIndexKeyHex((int) $user->id, $session);
    $other = bin2hex(random_bytes(32));

    $mine = $codec->derive(CounterpartyKey::DOMAIN, CBI_NORMALIZED, (int) $user->id, $session);

    expect($codec->deriveWithKey(CounterpartyKey::DOMAIN, CBI_NORMALIZED, (int) $user->id, $shared))->toBe($mine);
    expect($codec->deriveWithKey(CounterpartyKey::DOMAIN, CBI_NORMALIZED, (int) $user->id, $other))->not->toBe($mine);
});
