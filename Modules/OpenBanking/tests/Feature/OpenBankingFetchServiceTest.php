<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

/*
 * OpenBankingFetchService (19-09/19-10): the secrets file holds exactly
 * ONE live Enable Banking session (`session_id` + `institutionId`) at a
 * time — `EnableBankingHttpClient` always resolves its credentials from
 * that single file, never from the `open_banking_connections` row that
 * triggered the fetch. If a SECOND institution has since been (re-)linked
 * — overwriting the secrets file's `institutionId` — a fetch attempt for
 * the FIRST connection's row must fail loudly rather than silently pair
 * one bank's live session with another bank's `account_uid` (T-19-10-01
 * review-gate finding).
 */

final class OfsStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    public bool $called = false;

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->called = true;

        yield from [];
    }
}

function ofsUser(string $username): User
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
function ofsSeedConnection(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId(array_merge([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-asn-1',
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

function ofsSeedCredentials(string $institutionId): void
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);

    $repo = new OpenBankingSecretsRepository(new Filesystem, app(SecretShield::class));
    $repo->save(new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: 'fixture-session-id',
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
        bankScaHost: 'sca.bank.example',
        institutionId: $institutionId,
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

it('proceeds when the connection institution matches the active secrets-file session', function (): void {
    $user = ofsUser('ofs-match');
    $connectionId = ofsSeedConnection($user);
    ofsSeedCredentials('ASNBNL21');

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);
    $service->preview($connectionId, $user);

    expect($stub->called)->toBeTrue();
});

it('throws rather than silently fetching with a mismatched institution session', function (): void {
    $user = ofsUser('ofs-mismatch');
    // Connection row is for ASN, but the secrets file's LIVE session now
    // belongs to SNS — e.g. the user connected a second bank without
    // disconnecting the first.
    $connectionId = ofsSeedConnection($user, ['institution_id' => 'ASNBNL21', 'account_uid' => 'acc-uid-asn-1']);
    ofsSeedCredentials('SNSBNL2A');

    $stub = new OfsStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $stub);

    /** @var OpenBankingFetchService $service */
    $service = app(OpenBankingFetchService::class);

    expect(fn () => $service->preview($connectionId, $user))
        ->toThrow(RuntimeException::class, 'does not');

    expect($stub->called)->toBeFalse();
});
