<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

// The Reconnect button exists to lift needs_reauth. A callback that rotates
// the secret but leaves the status alone hands the user a working grant on a
// permanently dead inbox: IncrementalScanJob treats needs_reauth as terminal
// and BackfillInboxJob cannot even enter backfilling from it.

beforeEach(function (): void {
    Sleep::fake();

    $this->inboxRoot = storage_path('app/inbox');
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

function emailScanReconnectProvider(?string $refreshToken): GoogleOAuthProvider
{
    $token = new AccessTokenWithEmail(
        accessToken: 'NEW-ACCESS',
        refreshToken: $refreshToken,
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        email: 'reconnect@example.com',
    );

    return new class($token) extends GoogleOAuthProvider
    {
        public function __construct(private readonly AccessTokenWithEmail $token) {}

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            return new AuthorizationRequest('https://accounts.google.com/o/oauth2/auth?state='.$state, '');
        }

        public function exchangeAuthorizationCode(string $code, string $redirectUri, string $pkceVerifier = ''): AccessTokenWithEmail
        {
            return $this->token;
        }

        public function refreshAccessToken(string $refreshToken): AccessTokenWithEmail
        {
            return $this->token;
        }

        public function readEmail(string $accessToken): string
        {
            return $this->token->email;
        }
    };
}

function emailScanSeedNeedsReauthInbox(string $username): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'reconnect@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'needs_reauth',
        'last_history_id' => '12345',
        'error_message' => 'OAuth grant revoked or expired.',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

it('a successful Reconnect lifts needs_reauth back to idle', function (): void {
    [$user, $inboxId, $db] = emailScanSeedNeedsReauthInbox('reconnect-lifts');
    $this->actingAs($user);

    /** @var OAuthSecretsRepository $secrets */
    $secrets = $this->app->make(OAuthSecretsRepository::class);
    $secrets->saveProviderClient('gmail', 'fake.apps.googleusercontent.com', 'GOCSPX-fake', 'http://127.0.0.1:8000/oauth/callback/gmail');
    $secrets->saveInboxRefreshToken($inboxId, 'gmail', 'reconnect@example.com', 'OLD-DEAD-REFRESH', 'scope', null);

    $this->app->instance(GoogleOAuthProvider::class, emailScanReconnectProvider('NEW-GOOD-REFRESH'));

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id, existingInboxId: $inboxId);

    $this->get('/oauth/callback/gmail?state='.$state.'&code=fake-code')
        ->assertRedirect(route('inboxes.index'))
        ->assertSessionHas('open_backfill_modal');

    expect($secrets->loadInbox($inboxId)?->refreshToken)->toBe('NEW-GOOD-REFRESH');

    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['status', 'error_message']);
    expect($state->status)->toBe('idle')
        ->and($state->error_message)->toBeNull();
});

it('after a Reconnect the inbox actually scans again', function (): void {
    [$user, $inboxId, $db] = emailScanSeedNeedsReauthInbox('reconnect-scans');
    $this->actingAs($user);

    /** @var OAuthSecretsRepository $secrets */
    $secrets = $this->app->make(OAuthSecretsRepository::class);
    $secrets->saveProviderClient('gmail', 'fake.apps.googleusercontent.com', 'GOCSPX-fake', 'http://127.0.0.1:8000/oauth/callback/gmail');
    $secrets->saveInboxRefreshToken($inboxId, 'gmail', 'reconnect@example.com', 'OLD-DEAD-REFRESH', 'scope', null);

    $this->app->instance(GoogleOAuthProvider::class, emailScanReconnectProvider('NEW-GOOD-REFRESH'));

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id, existingInboxId: $inboxId);
    $this->get('/oauth/callback/gmail?state='.$state.'&code=fake-code');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['ics-sample-statement-notice'], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var BackfillInboxJob $backfill */
    $backfill = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$backfill, 'handle']);

    /** @var IncrementalScanJob $incremental */
    $incremental = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$incremental, 'handle']);

    $messages = $db->connection()->table('inbox_messages')->where('inbox_id', $inboxId)->count();
    $after = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['status']);

    expect($messages)->toBeGreaterThan(0)
        ->and($after->status)->toBe('idle');
});

// The new-inbox path rejects a token response with no refresh token outright.
// The reconnect path silently dropped BOTH tokens and still redirected with
// open_backfill_modal, so the user was told it worked.
it('a Reconnect whose token response carries no refresh token is rejected, not silently dropped', function (): void {
    [$user, $inboxId, $db] = emailScanSeedNeedsReauthInbox('reconnect-no-refresh');
    $this->actingAs($user);

    /** @var OAuthSecretsRepository $secrets */
    $secrets = $this->app->make(OAuthSecretsRepository::class);
    $secrets->saveProviderClient('gmail', 'fake.apps.googleusercontent.com', 'GOCSPX-fake', 'http://127.0.0.1:8000/oauth/callback/gmail');
    $secrets->saveInboxRefreshToken($inboxId, 'gmail', 'reconnect@example.com', 'OLD-DEAD-REFRESH', 'scope', null);

    $this->app->instance(GoogleOAuthProvider::class, emailScanReconnectProvider(null));

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = $this->app->make(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $user->id, existingInboxId: $inboxId);

    $this->get('/oauth/callback/gmail?state='.$state.'&code=fake-code')
        ->assertRedirect(route('inboxes.index'))
        ->assertSessionHas('oauth_failed')
        ->assertSessionMissing('open_backfill_modal');

    $after = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['status']);
    expect($after->status)->toBe('needs_reauth');
});
