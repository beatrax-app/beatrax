<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

function obcqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function obcqSeedCredentials(?string $institutionId): void
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
        bankScaHost: 'sca.bank.example',
        institutionId: $institutionId,
    ));
}

function obcqSeedConnection(User $user, string $institutionId, ?string $consentExpiresAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    $db->connection()->table('open_banking_connections')->insert([
        'user_id' => $user->id,
        'institution_id' => $institutionId,
        'account_uid' => 'acc-uid-1',
        'bank_display_name' => 'ignored — derived at read time',
        'enabled' => true,
        'consent_expires_at' => $consentExpiresAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function obcqQuery(): OpenBankingConnectionQuery
{
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-07-19 06:30:00');
        }
    };

    return new OpenBankingConnectionQuery(
        app(DatabaseManager::class),
        new OpenBankingSecretsRepository(new Filesystem, app(SecretShield::class), app(Encrypter::class)),
        $clock,
    );
}

beforeEach(function (): void {
    $this->secretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
});

afterEach(function (): void {
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
});

it('reports no connection when the secrets file names no institution', function (?string $institutionId): void {
    $user = obcqUser('obcq-no-institution-'.($institutionId ?? 'null'));
    obcqSeedCredentials($institutionId);

    expect(obcqQuery()->current($user->id))->toBeNull();
})->with([[null], ['']]);

// The secrets file and the connections table are written by different steps of
// the consent dance, so the file can name an institution the user has no row for.
it('reports no connection when no row matches the live session', function (): void {
    $user = obcqUser('obcq-no-row');
    obcqSeedCredentials('ASNBNL21');

    expect(obcqQuery()->current($user->id))->toBeNull();
});

it('reads the consent status from how much of the window is left', function (?string $expiresAt, string $expected): void {
    $user = obcqUser('obcq-status-'.md5($expiresAt ?? 'null'));
    obcqSeedCredentials('ASNBNL21');
    obcqSeedConnection($user, 'ASNBNL21', $expiresAt);

    $view = obcqQuery()->current($user->id);

    expect($view)->not->toBeNull()
        ->and($view->consentStatus)->toBe($expected);
})->with([
    // An unknown expiry is not evidence of a live consent.
    'never recorded' => [null, 'expired'],
    'already past' => ['2026-07-18 06:00:00', 'expired'],
    'exactly now' => ['2026-07-19 06:30:00', 'expired'],
    'inside the 14-day window' => ['2026-07-25 06:00:00', 'expiring'],
    'on the 14-day boundary' => ['2026-08-02 06:30:00', 'expiring'],
    'beyond the window' => ['2026-10-19 00:00:00', 'connected'],
]);

// bank_display_name is a column the callback controller never populates, so an
// unmapped institution has to fall back to its own id rather than render blank.
it('derives the bank display name from the institution id', function (string $institutionId, string $expected): void {
    $user = obcqUser('obcq-name-'.$institutionId);
    obcqSeedCredentials($institutionId);
    obcqSeedConnection($user, $institutionId, '2026-10-19 00:00:00');

    $view = obcqQuery()->current($user->id);

    expect($view)->not->toBeNull()
        ->and($view->bankDisplayName)->toBe($expected);
})->with([
    ['ASNBNL21', 'ASN Bank'],
    ['SNSBNL21', 'SNS (de Volksbank)'],
    ['RABONL2U', 'RABONL2U'],
]);
