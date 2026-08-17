<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Encryption\Encrypter;
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
        app(Encrypter::class),
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

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
});

it('connect redirects to settings with a flash when no institution_id is supplied', function (): void {
    $user = ocdUser('connect-no-institution');
    $this->actingAs($user);

    ocdSeedApplication($this->privateKeyPem);

    $response = $this->get('/oauth/connect/open-banking');

    $response->assertRedirect(route('settings.open-banking'));
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

    $response->assertRedirect(route('settings.open-banking'));
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

    $response->assertRedirect(route('settings.open-banking'));
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

    $response->assertRedirect(route('settings.open-banking'));
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

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
    expect(session('open_banking_failed'))->toContain('simulated write failure');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

// --- Connect controller: consent-URL resolution failures -------------
//
// Each of these drives one refusal in resolveConsentUrl()/resolveScaHost()/
// guardConsentRedirect() through to the single RuntimeException catch, and in
// doing so exercises the matching OpenBankingConnectException factory. The
// flashed message must be the factory's user-facing reason, never a raw error.

it('connect flashes when Enable Banking returns no consent URL', function (): void {
    $user = ocdUser('connect-no-url');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem);
    $noUrl = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'authorization_id' => 'auth-fixture-123',
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient($secrets, [$noUrl]));

    $response = $this->get('/oauth/connect/open-banking?institution_id=ASNBNL21');

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
    expect(session('open_banking_failed'))->toBe('Enable Banking did not return a consent URL.');
});

it('connect flashes when the consent URL has no parseable host', function (): void {
    $user = ocdUser('connect-unparseable');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem);
    // A non-empty string that parse_url() yields no host for, so
    // resolveScaHost() refuses it before persisting anything.
    $unparseable = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'https:///only-a-path',
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient($secrets, [$unparseable]));

    $response = $this->get('/oauth/connect/open-banking?institution_id=ASNBNL21');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking returned an unparseable consent URL.');
});

it('connect refuses a non-public consent host before it widens the egress allow-list', function (string $scaHost): void {
    $user = ocdUser('connect-nonpublic-'.substr(md5($scaHost), 0, 8));
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient($secrets, [ocdAuthResponse($scaHost)]));

    $response = $this->get('/oauth/connect/open-banking?institution_id=ASNBNL21');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking returned a non-public consent host.');

    // The bank SCA host must NOT have been persisted — the refusal happens
    // before persistResolvedScaHost() runs.
    expect($secrets->load()->bankScaHost)->toBeNull();
})->with([
    'loopback name' => ['localhost'],
    'private ipv4' => ['10.0.0.1'],
    'bare single-label host' => ['internalhost'],
]);

it('connect refuses a consent URL that is not https even when its host is public', function (): void {
    $user = ocdUser('connect-unsafe');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem);
    // http:// with a public host: resolveScaHost() accepts the host, then
    // guardConsentRedirect() rejects the non-https scheme as an open redirect.
    $httpUrl = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'http://sca.asnbank.example/mock-consent-flow',
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient($secrets, [$httpUrl]));

    $response = $this->get('/oauth/connect/open-banking?institution_id=ASNBNL21');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking returned an unsafe consent URL.');
});

// --- Callback controller: post-state completeConsent() failures ------

it('callback flashes wizard-incomplete when the stored credentials carry no institution id', function (): void {
    $user = ocdUser('callback-no-institution');
    $this->actingAs($user);

    // Seeded application WITHOUT an institution id: the wizard's bank-choice
    // step never completed, so completeConsent() refuses before any session
    // exchange.
    ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: null);

    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = $this->app->make(OpenBankingStateRepository::class);
    $state = $stateRepo->issueState($user->id);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Finish the Open Banking setup wizard first.');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

it('callback flashes when the session response carries no session id', function (): void {
    $user = ocdUser('callback-no-session');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');
    $noSession = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'accounts' => [['uid' => 'acc-fixture-1']],
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient($secrets, [$noSession]));

    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = $this->app->make(OpenBankingStateRepository::class);
    $state = $stateRepo->issueState($user->id);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking did not return a session id.');

    // No session id means no connection may be advertised — the insert never
    // ran (the throw is before upsertConnectionRow()).
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

it('compensating rollback: secret-write failure on a RE-LINK restores the row prior consent + account uid', function (): void {
    $user = ocdUser('callback-relink-rollback');
    $this->actingAs($user);

    $secrets = ocdSeedApplication($this->privateKeyPem, bankScaHost: 'sca.asnbank.example', institutionId: 'ASNBNL21');
    $client = ocdMockClient($secrets, [ocdSessionResponse('session-fixture-relink')]);
    $this->app->instance(EnableBankingHttpClient::class, $client);

    // Pre-existing connection row for this (user, institution): the upsert
    // takes the UPDATE branch, so a later secret-write failure must roll the
    // row back to these prior values rather than delete it.
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $priorConsent = '2025-01-01 00:00:00';
    $existingId = $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-original',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => $priorConsent,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => '2025-01-01 00:00:00',
        'updated_at' => '2025-01-01 00:00:00',
    ]);

    $throwingSecrets = new class($secrets) extends OpenBankingSecretsRepository
    {
        public function __construct(private readonly OpenBankingSecretsRepository $real)
        {
            // Skip parent constructor — every method delegates to the real
            // repository except the throw point under test.
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
            throw new SecretsWriteFailed('simulated re-link write failure');
        }
    };
    $this->app->instance(OpenBankingSecretsRepository::class, $throwingSecrets);

    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = $this->app->make(OpenBankingStateRepository::class);
    $state = $stateRepo->issueState($user->id);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toContain('simulated re-link write failure');

    // Exactly the one pre-existing row, rolled back to its prior consent +
    // account uid — never advertising a fresh consent the secrets file cannot
    // back.
    $rows = $db->connection()->table('open_banking_connections')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect((int) $row->id)->toBe((int) $existingId);
    expect($row->consent_expires_at)->toBe($priorConsent);
    expect($row->account_uid)->toBe('acc-original');
});
