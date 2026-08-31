<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

// Nobody is watching a 06:00 sync, so a run it could not file has to survive as
// unfinished work. Confirmed-with-nothing-written plus the idempotency key on
// the window is how a whole day of transactions left no trace anywhere. The
// attempt is NothingImported rather than Error: nothing errored, the account
// the rows landed in is unnamed.

const SNCR_UNNAMED_IBAN = 'NL22RABO0987654321';

const SNCR_BOOKED_ON = '2026-07-18';

// A bank answers for the window it was asked about and nothing else, which is
// what makes the advanced cursor lose the day: ask again from the day after,
// and this transaction is not in the answer.
final class SncrStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    public int $fetches = 0;

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->fetches++;

        $bookedOn = CarbonImmutable::parse(SNCR_BOOKED_ON);

        if ($bookedOn->lessThan($window->dateFrom) || $bookedOn->greaterThan($window->dateTo)) {
            return FetchWalk::exhausted(1, 0);
        }

        yield new SourceTransactionDto(
            bookedAt: $bookedOn,
            postedAt: $bookedOn,
            valueDate: $bookedOn,
            ownIban: SNCR_UNNAMED_IBAN,
            counterpartyIban: 'NL91ABNA0417164300',
            counterpartyName: 'Netflix',
            currency: 'EUR',
            amountMinor: -1299,
            sourceRef: 'eb-sncr-1',
            description: 'Netflix subscription',
            rawPayload: [],
            sourceRowIndex: 0,
        );

        return FetchWalk::exhausted(1, 1);
    }
}

function sncrSeedConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-sncr-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function sncrSeedCredentials(): void
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);

    $repo = new OpenBankingSecretsRepository(new Filesystem, app(SecretShield::class), app(Encrypter::class));
    $repo->save(new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: 'fixture-session-id',
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
        bankScaHost: 'sca.asnbank.example',
        institutionId: 'ASNBNL21',
    ));
}

function sncrConnectionRow(int $connectionId): stdClass
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var stdClass $row */
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    return $row;
}

beforeEach(function (): void {
    $this->secretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->connectionId = sncrSeedConnection($this->fixtureUser);
    sncrSeedCredentials();

    $this->adapter = new SncrStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $this->adapter);
});

afterEach(function (): void {
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow();
});

it('leaves the run unconfirmed and the cursor where it was when every fetched row names an account the ledger does not have', function (): void {
    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect($this->adapter->fetches)->toBe(1);
    expect(Transaction::query()->count())->toBe(0);

    /** @var ImportRun $run */
    $run = ImportRun::query()->where('user_id', $this->fixtureUser->id)->latest('id')->firstOrFail();
    expect($run->status)->not->toBe(ImportRunStatus::Confirmed->value);
    expect($run->confirmed_at)->toBeNull();

    $connection = sncrConnectionRow($this->connectionId);
    expect($connection->last_successful_sync_at)->toBeNull();
    expect($connection->last_attempt_status)->toBe(SyncAttemptStatus::NothingImported->value);
});

// The point of not advancing the cursor: the window stays open, so the rows
// arrive the moment the reader names the account they belong to. Advance it and
// the next fetch starts the day after, and this transaction is never offered
// again by anyone.
it('lands the same window once the account exists', function (): void {
    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect(Transaction::query()->count())->toBe(0);

    Account::query()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Rabo current',
        'slug' => 'rabo-current',
        'kind' => 'bank',
        'iban' => SNCR_UNNAMED_IBAN,
        'default_currency' => 'EUR',
    ]);

    $retry = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$retry, 'handle']);

    expect($this->adapter->fetches)->toBe(2);
    expect(Transaction::query()->count())->toBe(1);

    $connection = sncrConnectionRow($this->connectionId);
    expect($connection->last_successful_sync_at)->not->toBeNull();
    expect($connection->last_attempt_status)->toBe(SyncAttemptStatus::Ok->value);
});
