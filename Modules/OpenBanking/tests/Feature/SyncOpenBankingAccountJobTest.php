<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

final class SojaStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    public bool $called = false;

    public function __construct(private readonly ?Throwable $throws = null) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->called = true;
        if ($this->throws !== null) {
            throw $this->throws;
        }

        // This job's contract is the bookkeeping around a fetch, not dedup.
        yield from [];
    }
}

function sojaUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sojaSeedConnection(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId(array_merge([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function sojaSeedCredentials(): void
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

beforeEach(function (): void {
    $this->secretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));
});

afterEach(function (): void {
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow();
});

it('writes BOTH last_successful_sync_at and last_attempt_at on a successful fetch', function (): void {
    $user = sojaUser('sync-success');
    $connectionId = sojaSeedConnection($user);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']);

    expect($stub->called)->toBeTrue();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->not->toBeNull();
    expect($row->last_attempt_at)->not->toBeNull();
    expect($row->last_attempt_status)->toBe('ok');
});

it('is a no-op — no fetch, no timestamp write — when the connection is disabled', function (): void {
    $user = sojaUser('sync-disabled');
    $connectionId = sojaSeedConnection($user, ['enabled' => false]);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']);

    expect($stub->called)->toBeFalse();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->toBeNull();
    expect($row->last_attempt_at)->toBeNull();
    expect($row->last_attempt_status)->toBeNull();
});

it('is a no-op — no fetch, no timestamp write — when consent has expired', function (): void {
    $user = sojaUser('sync-expired');
    $connectionId = sojaSeedConnection($user, [
        'consent_expires_at' => CarbonImmutable::parse('2026-01-01 00:00:00')->toDateTimeString(),
    ]);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']);

    expect($stub->called)->toBeFalse();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();
    expect($row->last_attempt_at)->toBeNull();
});

it('updates ONLY last_attempt_* on a generic failure, leaves last_successful_sync_at unchanged, and rethrows', function (): void {
    $user = sojaUser('sync-failure');
    $priorSuccess = CarbonImmutable::parse('2026-07-18 06:00:00')->toDateTimeString();
    $connectionId = sojaSeedConnection($user, ['last_successful_sync_at' => $priorSuccess]);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter(
        EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 500, 'server error')
    );
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->toBe($priorSuccess);
    expect($row->last_attempt_at)->not->toBeNull();
    expect($row->last_attempt_status)->toBe('error');
});

it('a consent failure (HTTP 401) marks consent_failed, dispatches OpenBankingConsentFailed, and does NOT rethrow', function (): void {
    Event::fake([OpenBankingConsentFailed::class]);

    $user = sojaUser('sync-consent-failure');
    $connectionId = sojaSeedConnection($user);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter(
        EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 401, 'unauthorized')
    );
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']); // must NOT throw

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->toBeNull();
    expect($row->last_attempt_status)->toBe('consent_failed');

    Event::assertDispatched(
        OpenBankingConsentFailed::class,
        fn (OpenBankingConsentFailed $event): bool => $event->connectionId === $connectionId && $event->userId === $user->id
    );
});

it('a consent failure (HTTP 403) also marks consent_failed and dispatches OpenBankingConsentFailed', function (): void {
    Event::fake([OpenBankingConsentFailed::class]);

    $user = sojaUser('sync-forbidden');
    $connectionId = sojaSeedConnection($user);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter(
        EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 403, 'forbidden')
    );
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();
    expect($row->last_attempt_status)->toBe('consent_failed');

    Event::assertDispatched(OpenBankingConsentFailed::class);
});

it('is a no-op when the connection row was deleted between dispatch and pickup', function (): void {
    $user = sojaUser('sync-deleted');
    $connectionId = sojaSeedConnection($user);
    sojaSeedCredentials();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('open_banking_connections')->where('id', $connectionId)->delete();

    $stub = new SojaStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']);

    expect($stub->called)->toBeFalse();
});

// The early returns leave the row untouched: they are the job declining to run,
// and a timestamp would make a deleted user look like an unresponsive bank.

// user_id is nullable under ON DELETE CASCADE, so a cleared column is the only
// shape an orphaned row can take — the foreign key forbids a dangling id.
it('exits without touching the row when the connection has no user to sync for', function (): void {
    $user = sojaUser('soja-orphan');
    $connectionId = sojaSeedConnection($user, ['user_id' => null]);
    sojaSeedCredentials();

    $stub = new SojaStubRemoteSourceAdapter(null);
    app()->instance(RemoteSourceAdapter::class, $stub);

    app()->call([new SyncOpenBankingAccountJob($connectionId), 'handle']);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_attempt_at)->toBeNull()
        ->and($row->last_attempt_status)->toBeNull();
});

// Uniqueness is keyed on the connection, not the job: two schedule ticks would
// otherwise reconcile the same window against rows the first has not committed.
it('scopes queue uniqueness to the connection for ten minutes', function (): void {
    $job = new SyncOpenBankingAccountJob(17);

    expect($job->uniqueId())->toBe('17')
        ->and($job->uniqueFor())->toBe(600)
        ->and($job->uniqueVia())->toBeInstanceOf(Repository::class);
});
