<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\OAuth\InvalidStateException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Services\SecretsWriteFailed;

/*
 * Full consent/SCA dance (19-05 Task 2): the connect controller's
 * `/auth` call + resolved-SCA-host persistence, and the callback
 * controller's guard chain (provider-error -> CSRF-state -> no-code),
 * `/sessions` exchange, and DB-then-secret compensating rollback.
 *
 * Mirrors EmailScan's OAuthCallbackGmailTest structure: state is issued
 * directly via the repository (not by chaining an HTTP hit to the
 * connect route) so these scenarios do not depend on cross-request
 * session/cookie persistence in the test client. The connect
 * controller's own HTTP behaviour (redirect + SCA-host persistence) is
 * covered by its own dedicated test group below.
 */

beforeEach(function (): void {
    $this->obSecretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->obSecretsPath)) {
        @unlink($this->obSecretsPath);
    }
    if (is_file($this->obSecretsPath.'.tmp')) {
        @unlink($this->obSecretsPath.'.tmp');
    }

    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);
    $this->privateKeyPem = $privateKeyPem;
});

afterEach(function (): void {
    if (is_file($this->obSecretsPath)) {
        @unlink($this->obSecretsPath);
    }
    if (is_file($this->obSecretsPath.'.tmp')) {
        @unlink($this->obSecretsPath.'.tmp');
    }
});

function ocdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ocdSeedApplication(string $privateKeyPem, ?string $bankScaHost = null, ?string $institutionId = null): OpenBankingSecretsRepository
{
    $repo = new OpenBankingSecretsRepository(
        new Filesystem,
        app(SecretShield::class),
    );

    $repo->save(new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: $bankScaHost,
        institutionId: $institutionId,
    ));

    return $repo;
}

/**
 * Builds an EnableBankingHttpClient test double whose Guzzle transport
 * is a MockHandler replaying the given queued responses in order — the
 * `makeHttpClient()` override hook `EnableBankingHttpClientSsrfTest`
 * already establishes. `baseUri()` is left at its real value
 * (api.enablebanking.com), which is always allow-listed, so no real
 * network call is ever attempted regardless.
 *
 * @param  list<Response>  $responses
 */
function ocdMockClient(OpenBankingSecretsRepository $secrets, array $responses): EnableBankingHttpClient
{
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::now();
        }
    };
    $jwtSigner = new EnableBankingJwtSigner($clock);
    $mock = new MockHandler($responses);

    return new class($secrets, $jwtSigner, $mock) extends EnableBankingHttpClient
    {
        public function __construct(
            OpenBankingSecretsRepository $secrets,
            EnableBankingJwtSigner $jwtSigner,
            private readonly MockHandler $mock,
        ) {
            parent::__construct($secrets, $jwtSigner);
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        }
    };
}

function ocdAuthResponse(string $scaHost = 'sca.asnbank.example'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'https://'.$scaHost.'/mock-consent-flow?token=xyz',
        'authorization_id' => 'auth-fixture-123',
    ], JSON_THROW_ON_ERROR));
}

function ocdSessionResponse(string $sessionId = 'session-fixture-abc'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'session_id' => $sessionId,
        'accounts' => [['uid' => 'acc-fixture-1']],
    ], JSON_THROW_ON_ERROR));
}

// --- Connect controller ---------------------------------------------

it('connect redirects to the EB consent URL and persists the resolved bank SCA host + institution id', function (): void {
    $user = ocdUser('connect-happy');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem);
    $client = ocdMockClient($secrets, [ocdAuthResponse('sca.asnbank.example')]);
    $this->app->instance(EnableBankingHttpClient::class, $client);

    $response = $this->get('/oauth/connect/open-banking?institution_id=ASNBNL21');

    $response->assertRedirect('https://sca.asnbank.example/mock-consent-flow?token=xyz');

    $loaded = $secrets->load();
    expect($loaded)->not->toBeNull();
    expect($loaded->bankScaHost)->toBe('sca.asnbank.example');
    expect($loaded->institutionId)->toBe('ASNBNL21');
    expect($loaded->applicationId)->toBe('fixture-application-id');
});

it('connect redirects to settings with a flash when the wizard has not been completed', function (): void {
    $user = ocdUser('connect-no-app');
    $this->actingAs($user);

    $response = $this->get('/oauth/connect/open-banking?institution_id=ASNBNL21');

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('open_banking_failed');
});

it('connect redirects to settings with a flash when no institution_id is supplied', function (): void {
    $user = ocdUser('connect-no-institution');
    $this->actingAs($user);

    ocdSeedApplication($this->privateKeyPem);

    $response = $this->get('/oauth/connect/open-banking');

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('open_banking_failed');
});

// --- Callback controller ---------------------------------------------

it('callback happy path creates one open_banking_connections row and persists the session', function (): void {
    $user = ocdUser('callback-happy');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');
    $client = ocdMockClient($secrets, [ocdSessionResponse('session-fixture-abc')]);
    $this->app->instance(EnableBankingHttpClient::class, $client);

    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = $this->app->make(OpenBankingStateRepository::class);
    $state = $stateRepo->issueState($user->id);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code-abcdef');

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('open_banking_connected');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')
        ->where('user_id', $user->id)
        ->where('institution_id', 'ASNBNL21')
        ->first();
    expect($row)->not->toBeNull();
    expect((bool) $row->enabled)->toBeFalse();
    expect($row->consent_expires_at)->not->toBeNull();
    // 19-09: the FIRST accounts[] entry's uid is persisted so
    // OpenBankingFetchService has a value to thread into
    // RemoteSourceAdapter::fetch() — see EnableBankingHttpClient::
    // accountUidFrom() and this fixture's ocdSessionResponse() shape.
    expect($row->account_uid)->toBe('acc-fixture-1');

    $loaded = $secrets->load();
    expect($loaded->sessionId)->toBe('session-fixture-abc');
    expect($loaded->bankScaHost)->toBe('sca.asnbank.example');
});

it('callback with mismatched state raises InvalidStateException and inserts no rows', function (): void {
    $user = ocdUser('callback-mismatch');
    $this->actingAs($user);

    ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');

    $this->withoutExceptionHandling();

    expect(function (): void {
        $this->get('/oauth/callback/open-banking?state=not-issued&code=fake');
    })->toThrow(InvalidStateException::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

it('callback with provider error redirects with open_banking_canceled flash and inserts no rows', function (): void {
    $user = ocdUser('callback-canceled');
    $this->actingAs($user);

    ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');

    $response = $this->get('/oauth/callback/open-banking?error=access_denied&error_description=user%20denied');

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('open_banking_canceled');
    expect(session('open_banking_canceled'))->toContain('user denied');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

it('callback with no code redirects with a flash and inserts no rows', function (): void {
    $user = ocdUser('callback-no-code');
    $this->actingAs($user);

    ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');

    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = $this->app->make(OpenBankingStateRepository::class);
    $state = $stateRepo->issueState($user->id);

    $response = $this->get('/oauth/callback/open-banking?state='.$state);

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('open_banking_failed');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

it('compensating rollback: secret-write failure after a NEW row insert deletes the row', function (): void {
    $user = ocdUser('callback-rollback');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');
    $client = ocdMockClient($secrets, [ocdSessionResponse('session-fixture-xyz')]);
    $this->app->instance(EnableBankingHttpClient::class, $client);

    // Substitute a secrets repository whose save() always fails, to
    // exercise the compensating-rollback branch. load()/hasApplication()
    // delegate to the real repository so the earlier guard checks and
    // the EB client's own credential load still see the seeded fixture.
    $throwingSecrets = new class($secrets) extends OpenBankingSecretsRepository
    {
        public function __construct(private readonly OpenBankingSecretsRepository $real)
        {
            // Skip parent constructor — every method delegates to the
            // real repository except the throw point.
        }

        public function hasApplication(): bool
        {
            return $this->real->hasApplication();
        }

        public function load(): ?OpenBankingCredentials
        {
            return $this->real->load();
        }

        public function save(OpenBankingCredentials $credentials): void
        {
            throw new SecretsWriteFailed('simulated write failure');
        }

        public function clear(): void
        {
            $this->real->clear();
        }
    };
    $this->app->instance(OpenBankingSecretsRepository::class, $throwingSecrets);

    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = $this->app->make(OpenBankingStateRepository::class);
    $state = $stateRepo->issueState($user->id);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake');

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('open_banking_failed');
    expect(session('open_banking_failed'))->toContain('simulated write failure');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});
