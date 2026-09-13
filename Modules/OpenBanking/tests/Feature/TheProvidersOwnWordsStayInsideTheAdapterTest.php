<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsFile;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;
use Modules\OpenBanking\Tests\Support\SojaStubRemoteSourceAdapter;

uses(RefreshDatabase::class);

// A shape a real 4xx body carries: the aggregator echoes the request back, and
// the request to /sessions IS the authorization code. Nothing in this module
// knows what a bank puts in an error body, which is the whole reason none of it
// may be flashed, stored or synced.
const POWS_BODY = '{"error":"invalid_grant","request":{"code":"AUTHZ-CODE-9d4f1a"}}';

const POWS_PATH = '/Users/nobody/Library/Application Support/Beatrax/storage/app/secrets/open-banking/1.json';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-19 06:30:00');
    $this->powsUserIds = [];
});

afterEach(function (): void {
    foreach ($this->powsUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
    }
    CarbonImmutable::setTestNow();
});

function powsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function powsRsaKey(): string
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $pem);

    return $pem;
}

/**
 * @param  list<Response>  $responses
 */
function powsMockClient(array $responses): EnableBankingHttpClient
{
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::now();
        }
    };

    return new class(new EnableBankingJwtSigner($clock), new MockHandler($responses)) extends EnableBankingHttpClient
    {
        public function __construct(EnableBankingJwtSigner $jwtSigner, private readonly MockHandler $mock)
        {
            parent::__construct($jwtSigner);
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        }
    };
}

function powsThrowingSecrets(): OpenBankingSecretsRepository
{
    return new class(app(OpenBankingSecretsFile::class)) extends OpenBankingSecretsRepository
    {
        public function rememberSession(
            int $userId,
            string $institutionId,
            string $sessionId,
            CarbonImmutable $consentExpiresAt,
        ): void {
            throw new SecretsWriteFailed('OpenBankingSecretsFile: atomic rename failed from '.POWS_PATH.'.tmp to '.POWS_PATH.'.');
        }
    };
}

function powsConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// The reader's half of a refusal is the reader's own words — the rule the
// state-mismatch case already states and enforces. An aggregator refusal
// carried the provider's instead, in English, on every locale.
it('flashes a reader sentence rather than the aggregator response body', function (): void {
    $user = powsUser('pows-api-callback');
    $this->powsUserIds[] = $user->id;
    $this->actingAs($user);

    OpenBankingSecretsFixture::repository()->saveApplication($user->id, 'app-id', powsRsaKey());
    $this->app->instance(EnableBankingHttpClient::class, powsMockClient([
        new Response(401, ['Content-Type' => 'application/json'], POWS_BODY),
    ]));

    /** @var OpenBankingStateRepository $states */
    $states = app(OpenBankingStateRepository::class);
    $state = $states->issueState($user->id, OpenBankingSecretsFixture::INSTITUTION_ID);

    $this->get('/oauth/callback/open-banking?state='.$state.'&code=AUTHZ-CODE-9d4f1a')
        ->assertRedirect(route('settings.open-banking'))
        ->assertSessionHas('open_banking_failed');

    expect((string) session('open_banking_failed'))
        ->not->toContain('AUTHZ-CODE-9d4f1a')
        ->not->toContain('invalid_grant')
        ->not->toContain('api.enablebanking.com');
});

// Same shape on the outward half of the dance.
it('flashes a reader sentence when the aggregator refuses to start a consent', function (): void {
    $user = powsUser('pows-api-connect');
    $this->powsUserIds[] = $user->id;
    $this->actingAs($user);

    OpenBankingSecretsFixture::repository()->saveApplication($user->id, 'app-id', powsRsaKey());
    $this->app->instance(EnableBankingHttpClient::class, powsMockClient([
        new Response(500, ['Content-Type' => 'application/json'], POWS_BODY),
    ]));

    $this->get('/oauth/connect/open-banking?institution_id='.OpenBankingSecretsFixture::INSTITUTION_ID)
        ->assertRedirect(route('settings.open-banking'));

    expect((string) session('open_banking_failed'))
        ->not->toContain('AUTHZ-CODE-9d4f1a')
        ->not->toContain('invalid_grant')
        ->not->toContain('api.enablebanking.com');
});

// The secrets file is addressed by absolute path, and the reader's own home
// directory is in it. OpenBankingCredentialsException already learned this;
// the write failure beside it did not.
it('flashes a reader sentence rather than the absolute path of the secrets file', function (): void {
    $user = powsUser('pows-write-failed');
    $this->powsUserIds[] = $user->id;
    $this->actingAs($user);

    OpenBankingSecretsFixture::repository()->saveApplication($user->id, 'app-id', powsRsaKey());
    $this->app->instance(EnableBankingHttpClient::class, powsMockClient([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'session_id' => 'session-fixture-abc',
            'accounts' => [['uid' => 'acc-fixture-1']],
        ], JSON_THROW_ON_ERROR)),
    ]));
    $this->app->instance(OpenBankingSecretsRepository::class, powsThrowingSecrets());

    /** @var OpenBankingStateRepository $states */
    $states = app(OpenBankingStateRepository::class);
    $state = $states->issueState($user->id, OpenBankingSecretsFixture::INSTITUTION_ID);

    $this->get('/oauth/callback/open-banking?state='.$state.'&code=fake')
        ->assertRedirect(route('settings.open-banking'));

    expect((string) session('open_banking_failed'))
        ->not->toContain(POWS_PATH)
        ->not->toContain('atomic rename')
        ->and((string) session('open_banking_failed'))->toBe(
            trans('openbanking::messages.errors.connection_not_saved'),
        );
});

// system_alerts is an owned, synced table: what lands in metadata here is
// replayed onto every paired device. `reason` is the exception CLASS
// everywhere else in the tree — SafeExceptionContext::describe() defines it —
// and this one site wrote 500 characters of the provider's own response.
it('never files the provider response body onto the alert row that syncs', function (): void {
    $user = powsUser('pows-alert-metadata');
    $this->powsUserIds[] = $user->id;
    OpenBankingSecretsFixture::seed($user->id, consentExpiresAt: CarbonImmutable::now()->addDays(180));
    $connectionId = powsConnection($user);

    app()->instance(RemoteSourceAdapter::class, new SojaStubRemoteSourceAdapter(
        EnableBankingApiException::errorStatus(
            'GET https://api.enablebanking.com/accounts/acc-uid-fixture-1/transactions',
            401,
            POWS_BODY,
        ),
    ));

    $job = new SyncOpenBankingAccountJob($connectionId);
    app()->call([$job, 'handle']);

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->firstOrFail();

    $stored = json_encode($alert->metadata, JSON_THROW_ON_ERROR);

    expect($stored)
        ->not->toContain('AUTHZ-CODE-9d4f1a')
        ->not->toContain('invalid_grant')
        ->not->toContain('acc-uid-fixture-1')
        ->and($alert->metadata['reason'] ?? null)->toBe(EnableBankingApiException::class);
});
