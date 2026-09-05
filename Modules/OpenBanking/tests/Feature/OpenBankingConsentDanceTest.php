<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsFile;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

// State is issued straight through OpenBankingStateRepository, so no scenario
// here depends on cross-request session persistence in the test client. The
// state is also where the institution now travels: the callback finishes the
// bank it was issued for, and never asks the secrets file which bank that was.

beforeEach(function (): void {
    $this->ocdUserIds = [];

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
    foreach ($this->ocdUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
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

function ocdSecrets(): OpenBankingSecretsRepository
{
    return OpenBankingSecretsFixture::repository();
}

// A real RSA key, because the JWT assertion is signed before the mocked
// transport ever sees the request.
function ocdSeedApplication(User $user, string $privateKeyPem, string $applicationId = OpenBankingSecretsFixture::APPLICATION_ID): void
{
    ocdSecrets()->saveApplication($user->id, $applicationId, $privateKeyPem);
}

/**
 * @param  list<Response>  $responses
 */
function ocdMockClient(array $responses): EnableBankingHttpClient
{
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::now();
        }
    };
    $mock = new MockHandler($responses);

    return new class(new EnableBankingJwtSigner($clock), $mock) extends EnableBankingHttpClient
    {
        public function __construct(
            EnableBankingJwtSigner $jwtSigner,
            private readonly MockHandler $mock,
        ) {
            parent::__construct($jwtSigner);
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        }
    };
}

// Every other method delegates to the real repository, so the earlier guards
// still pass and only the post-commit session write fails.
function ocdThrowingSecrets(string $failure): OpenBankingSecretsRepository
{
    return new class(app(OpenBankingSecretsFile::class), $failure) extends OpenBankingSecretsRepository
    {
        public function __construct(OpenBankingSecretsFile $file, private readonly string $failure)
        {
            parent::__construct($file);
        }

        public function rememberSession(
            int $userId,
            string $institutionId,
            string $sessionId,
            CarbonImmutable $consentExpiresAt,
        ): void {
            throw new SecretsWriteFailed($this->failure);
        }
    };
}

function ocdIssueState(User $user, string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): string
{
    /** @var OpenBankingStateRepository $stateRepo */
    $stateRepo = app(OpenBankingStateRepository::class);

    return $stateRepo->issueState($user->id, $institutionId);
}

function ocdAuthResponse(string $scaHost = 'sca.asnbank.example'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'https://'.$scaHost.'/mock-consent-flow?token=xyz',
        'authorization_id' => 'auth-fixture-123',
    ], JSON_THROW_ON_ERROR));
}

function ocdSessionResponse(string $sessionId = 'session-fixture-abc', string $accountUid = 'acc-fixture-1'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'session_id' => $sessionId,
        'accounts' => [['uid' => $accountUid]],
    ], JSON_THROW_ON_ERROR));
}

function ocdRowCount(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count();
}

it('connect redirects to the EB consent URL and records the bank SCA host under the institution it was resolved for', function (): void {
    $user = ocdUser('connect-happy');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([ocdAuthResponse('sca.asnbank.example')]));

    $response = $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID);

    $response->assertRedirect('https://sca.asnbank.example/mock-consent-flow?token=xyz');

    $loaded = ocdSecrets()->load($user->id, OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($loaded)->not->toBeNull();
    expect($loaded->bankScaHost)->toBe('sca.asnbank.example');
    expect($loaded->institutionId)->toBe(OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($loaded->applicationId)->toBe(OpenBankingSecretsFixture::APPLICATION_ID);
});

// The SCA host is merged into the institution's own record, so beginning a
// consent at a second bank cannot repoint the first one's.
it('connect leaves an already-linked bank\'s session and host untouched', function (): void {
    $user = ocdUser('connect-second-bank');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    OpenBankingSecretsFixture::seed($user->id);
    ocdSeedApplication($user, $this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([ocdAuthResponse('sca.snsbank.example')]));

    $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::SECOND_INSTITUTION_ID)
        ->assertRedirect('https://sca.snsbank.example/mock-consent-flow?token=xyz');

    $first = ocdSecrets()->load($user->id, OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($first->sessionId)->toBe('fixture-session');
    expect($first->bankScaHost)->toBe('sca.asnbank.example');

    $second = ocdSecrets()->load($user->id, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);
    expect($second->bankScaHost)->toBe('sca.snsbank.example');
    expect($second->sessionId)->toBeNull();
});

it('connect redirects to settings with a flash when the wizard has not been completed', function (): void {
    $user = ocdUser('connect-no-app');
    $this->actingAs($user);

    $response = $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID);

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
});

it('connect redirects to settings with a flash when no institution_id is supplied', function (): void {
    $user = ocdUser('connect-no-institution');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);

    $response = $this->get('/oauth/connect/open-banking');

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
});

it('callback happy path creates one open_banking_connections row and persists the session', function (): void {
    $user = ocdUser('callback-happy');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([ocdSessionResponse('session-fixture-abc')]));

    $state = ocdIssueState($user);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code-abcdef');

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_connected');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')
        ->where('user_id', $user->id)
        ->where('institution_id', OpenBankingSecretsFixture::INSTITUTION_ID)
        ->first();
    expect($row)->not->toBeNull();
    expect((bool) $row->enabled)->toBeFalse();
    expect($row->consent_expires_at)->not->toBeNull();
    // The FIRST accounts[] entry's uid is the one persisted, which is the value
    // OpenBankingFetchService later threads into RemoteSourceAdapter::fetch().
    expect($row->account_uid)->toBe('acc-fixture-1');

    $loaded = ocdSecrets()->load($user->id, OpenBankingSecretsFixture::INSTITUTION_ID);
    expect($loaded->sessionId)->toBe('session-fixture-abc');
});

// The whole point of keying the store by bank: a reader who already holds one
// connected bank can finish consent at a second and end up with both, rather
// than with the first one's session overwritten by the second's.
it('a reader with one bank connected can complete consent for a SECOND and keep both', function (): void {
    $user = ocdUser('callback-second-bank');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    OpenBankingSecretsFixture::seed($user->id);
    ocdSeedApplication($user, $this->privateKeyPem);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $firstRowId = $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-fixture-asn',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([
        ocdSessionResponse('session-fixture-sns', 'acc-fixture-sns'),
    ]));

    $state = ocdIssueState($user, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);

    $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code-second')
        ->assertRedirect(route('settings.open-banking'))
        ->assertSessionHas('open_banking_connected');

    expect(ocdRowCount($user))->toBe(2);

    $secondRow = $db->connection()->table('open_banking_connections')
        ->where('user_id', $user->id)
        ->where('institution_id', OpenBankingSecretsFixture::SECOND_INSTITUTION_ID)
        ->first();
    expect($secondRow)->not->toBeNull();
    expect($secondRow->account_uid)->toBe('acc-fixture-sns');

    // The first bank's row was not touched, and neither was its session.
    $firstRow = $db->connection()->table('open_banking_connections')->where('id', $firstRowId)->first();
    expect($firstRow->account_uid)->toBe('acc-fixture-asn');
    expect((bool) $firstRow->enabled)->toBeTrue();

    $secrets = ocdSecrets();
    expect($secrets->load($user->id, OpenBankingSecretsFixture::INSTITUTION_ID)->sessionId)->toBe('fixture-session');
    expect($secrets->load($user->id, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID)->sessionId)->toBe('session-fixture-sns');
    expect($secrets->connectedInstitutions($user->id))->toBe([
        OpenBankingSecretsFixture::INSTITUTION_ID,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
    ]);
});

// Asserted through the handler, not around it. With withoutExceptionHandling()
// this read as covered while a real reader got a 500 stack trace in the middle
// of connecting their bank -- the test watched the throw and never the outcome.
// A state that does not match is an ORDINARY way to reach this URL: a link
// opened twice, a back button, a tab left overnight.
it('callback with mismatched state redirects with a reason and inserts no rows', function (): void {
    $user = ocdUser('callback-mismatch');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);

    $response = $this->get('/oauth/callback/open-banking?state=not-issued&code=fake');

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');

    expect(ocdRowCount($user))->toBe(0);
});

// The reason is the reader's, so it has to be the translated string rather than
// the mechanism. `Lang::get` returns the key back when it is missing, which is
// what a locale that never got this line would flash.
it('flashes a translated reason rather than the exception mechanism', function (): void {
    $user = ocdUser('callback-mismatch-copy');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);

    $expected = trans('openbanking::messages.errors.oauth_state_mismatch');

    expect($expected)->not->toBe('openbanking::messages.errors.oauth_state_mismatch');

    $this->get('/oauth/callback/open-banking?state=not-issued&code=fake')
        ->assertSessionHas('open_banking_failed', $expected);
});

it('callback with provider error redirects with open_banking_canceled flash and inserts no rows', function (): void {
    $user = ocdUser('callback-canceled');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);

    $response = $this->get('/oauth/callback/open-banking?error=access_denied&error_description=user%20denied');

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_canceled');
    expect(session('open_banking_canceled'))->toContain('user denied');

    expect(ocdRowCount($user))->toBe(0);
});

it('callback with no code redirects with a flash and inserts no rows', function (): void {
    $user = ocdUser('callback-no-code');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);

    $state = ocdIssueState($user);

    $response = $this->get('/oauth/callback/open-banking?state='.$state);

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');

    expect(ocdRowCount($user))->toBe(0);
});

it('compensating rollback: secret-write failure after a NEW row insert deletes the row', function (): void {
    $user = ocdUser('callback-rollback');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([ocdSessionResponse('session-fixture-xyz')]));
    $this->app->instance(OpenBankingSecretsRepository::class, ocdThrowingSecrets('simulated write failure'));

    $state = ocdIssueState($user);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake');

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
    expect(session('open_banking_failed'))->toContain('simulated write failure');

    expect(ocdRowCount($user))->toBe(0);
});

it('connect flashes when Enable Banking returns no consent URL', function (): void {
    $user = ocdUser('connect-no-url');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $noUrl = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'authorization_id' => 'auth-fixture-123',
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([$noUrl]));

    $response = $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID);

    $response->assertRedirect(route('settings.open-banking'));
    $response->assertSessionHas('open_banking_failed');
    expect(session('open_banking_failed'))->toBe('Enable Banking did not return a consent URL.');
});

it('connect flashes when the consent URL has no parseable host', function (): void {
    $user = ocdUser('connect-unparseable');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    // A non-empty string parse_url() yields no host for.
    $unparseable = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'https:///only-a-path',
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([$unparseable]));

    $response = $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID);

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking returned an unparseable consent URL.');
});

it('connect refuses a non-public consent host before it widens the egress allow-list', function (string $scaHost): void {
    $user = ocdUser('connect-nonpublic-'.substr(md5($scaHost), 0, 8));
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([ocdAuthResponse($scaHost)]));

    $response = $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID);

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking returned a non-public consent host.');

    // The refusal happens before rememberScaHost() runs.
    expect(ocdSecrets()->load($user->id, OpenBankingSecretsFixture::INSTITUTION_ID)->bankScaHost)->toBeNull();
})->with([
    'loopback name' => ['localhost'],
    'private ipv4' => ['10.0.0.1'],
    'bare single-label host' => ['internalhost'],
    // Every one of these resolves to loopback or an internal address, and
    // every one of them used to be answered "public" because the old check
    // fell through to "contains a dot" whenever FILTER_VALIDATE_IP could not
    // parse the string.
    'octal loopback' => ['0177.0.0.1'],
    'short-form loopback' => ['127.1'],
    'hex loopback' => ['0x7f.0x0.0x0.0x1'],
    'ipv6-mapped loopback' => ['[::ffff:127.0.0.1]'],
    'absolute loopback name' => ['localhost.'],
    'cloud metadata name' => ['metadata.google.internal'],
    'mdns name' => ['printer.local'],
]);

it('connect refuses a consent URL that is not https even when its host is public', function (): void {
    $user = ocdUser('connect-unsafe');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    // http:// with a public host: resolveScaHost() accepts the host, then
    // guardConsentRedirect() rejects the non-https scheme as an open redirect.
    $httpUrl = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'http://sca.asnbank.example/mock-consent-flow',
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([$httpUrl]));

    $response = $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID);

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking returned an unsafe consent URL.');
});

// The wizard writes the private key at step 1 and the application id at step 3,
// so a reader who stopped in between has a file that reads back with an empty
// application id. Both halves of "no application" are refused the same way.
it('callback flashes wizard-incomplete when no application id has been registered', function (bool $seedPartialKey): void {
    $user = ocdUser('callback-no-app-'.($seedPartialKey ? 'partial' : 'absent'));
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    if ($seedPartialKey) {
        ocdSeedApplication($user, $this->privateKeyPem, applicationId: '');
    }

    $state = ocdIssueState($user);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Finish the Open Banking setup wizard first.');

    expect(ocdRowCount($user))->toBe(0);
})->with([
    'keypair generated, application id never saved' => [true],
    'wizard never started' => [false],
]);

it('callback flashes when the session response carries no session id', function (): void {
    $user = ocdUser('callback-no-session');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $noSession = new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'accounts' => [['uid' => 'acc-fixture-1']],
    ], JSON_THROW_ON_ERROR));
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([$noSession]));

    $state = ocdIssueState($user);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toBe('Enable Banking did not return a session id.');

    // The throw is before upsertConnectionRow(), so no row was ever inserted.
    expect(ocdRowCount($user))->toBe(0);
});

it('compensating rollback: secret-write failure on a RE-LINK restores the row prior consent + account uid', function (): void {
    $user = ocdUser('callback-relink-rollback');
    $this->ocdUserIds[] = $user->id;
    $this->actingAs($user);

    ocdSeedApplication($user, $this->privateKeyPem);
    $this->app->instance(EnableBankingHttpClient::class, ocdMockClient([ocdSessionResponse('session-fixture-relink')]));

    // A pre-existing row for this (user, institution) sends the upsert down its
    // UPDATE branch, so the rollback must restore these values, not delete.
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $priorConsent = '2025-01-01 00:00:00';
    $existingId = $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-original',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => $priorConsent,
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => '2025-01-01 00:00:00',
        'updated_at' => '2025-01-01 00:00:00',
    ]);

    $this->app->instance(OpenBankingSecretsRepository::class, ocdThrowingSecrets('simulated re-link write failure'));

    $state = ocdIssueState($user);

    $response = $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake');

    $response->assertRedirect(route('settings.open-banking'));
    expect(session('open_banking_failed'))->toContain('simulated re-link write failure');

    // Rolled back to the prior consent + account uid, never advertising a fresh
    // consent the secrets file cannot back.
    $rows = $db->connection()->table('open_banking_connections')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect((int) $row->id)->toBe((int) $existingId);
    expect($row->consent_expires_at)->toBe($priorConsent);
    expect($row->account_uid)->toBe('acc-original');
});
