<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\AuthorizationRequest;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\EmailScan\Public\Dto\InboxHealthLine;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

// /inboxes is in the phone sidebar and ungated, and every sentence on it
// promised a scan the device never performs: all five email-scan schedule
// entries are Schedule::call() closures, so $event->command is null and
// SchedulerManifestGenerator drops each one before it reaches a manifest.

function tisnUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function tisnSeedInbox(User $owner, string $provider = 'gmail'): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => $provider,
        'email' => 'reader@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'last_scan_at' => null,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

it('keeps the desktop copy on the platform whose scheduler really scans', function (): void {
    $this->actingAs(tisnUser('inbox-desktop'));

    Livewire::test(InboxesPage::class)
        ->assertSee('so Beatrax can scan them for receipts')
        ->assertSee('Import receipts from PayPal, ICS Cards, Google Play, and other merchants')
        ->assertDontSee('This phone does not scan mailboxes')
        ->assertDontSee('runs in the desktop app');
});

it('tells a phone reader at the top of the screen that nothing here scans', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    $this->actingAs(tisnUser('inbox-phone-empty'));

    Livewire::test(InboxesPage::class)
        ->assertSee('This phone does not scan mailboxes')
        ->assertSee('Inbox scanning runs in the desktop app, not on this phone.')
        ->assertDontSee('Connect Gmail and Microsoft 365 inboxes so Beatrax can scan them for receipts.');
});

// The banner used to add "and inboxes connected in the desktop app are not
// listed on this phone", which the rows underneath it refuted: they sync, so
// the phone lists them, with a last-scanned time and a Scan now beside each.
// The heading above already carries the half that is true.
it('does not tell a phone reader that the inboxes below it are not there', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    $reader = tisnUser('inbox-phone-listed');
    $this->actingAs($reader);
    tisnSeedInbox($reader);

    Livewire::test(InboxesPage::class)
        ->assertSee('reader@example.com')
        ->assertDontSee('are not listed on this phone');
});

it('does not offer a phone reader an empty-state hero that promises a scan', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');
    $this->actingAs(tisnUser('inbox-phone-hero'));

    Livewire::test(InboxesPage::class)
        ->assertSee('imported by the desktop app')
        ->assertDontSee('Import receipts from PayPal, ICS Cards, Google Play, and other merchants');
});

it('drops the yet from not scanned yet where no schedule will ever follow it', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    $reader = tisnUser('inbox-phone-row');
    $this->actingAs($reader);
    tisnSeedInbox($reader);

    Livewire::test(InboxesPage::class)
        ->assertSee('not scanned on this phone')
        ->assertDontSee('not scanned yet');
});

it('keeps not scanned yet on a desktop, where the next tick really is coming', function (): void {
    $reader = tisnUser('inbox-desktop-row');
    $this->actingAs($reader);
    tisnSeedInbox($reader);

    Livewire::test(InboxesPage::class)
        ->assertSee('not scanned yet')
        ->assertDontSee('not scanned on this phone');
});

it('does not tell a phone reader that a second account here would be scanned', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');
    $reader = tisnUser('inbox-phone-cards');
    $this->actingAs($reader);
    tisnSeedInbox($reader);

    Livewire::test(InboxesPage::class)
        ->assertSee('Gmail is scanned by the desktop app.')
        ->assertSee('Microsoft 365 and Outlook.com are scanned by the desktop app.')
        ->assertDontSee('Connect a Gmail account so Beatrax can scan it for receipts.')
        ->assertDontSee('Connect a Microsoft 365 or Outlook.com account so Beatrax can scan it for receipts.');
});

it('keeps the add-another card copy on a desktop', function (): void {
    $reader = tisnUser('inbox-desktop-cards');
    $this->actingAs($reader);
    tisnSeedInbox($reader);

    Livewire::test(InboxesPage::class)
        ->assertSee('Connect a Gmail account so Beatrax can scan it for receipts.')
        ->assertDontSee('Gmail is scanned by the desktop app.');
});

// The health tile only renders for a reader who has an inbox row locally, and
// inboxes are not in the merge registry, so on a phone that means one connected
// there — which no schedule will ever scan.
it('does not promise the dashboard health tile a scan the phone will not run', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $tile = new EmailScanHealthTile(
        lines: [new InboxHealthLine('gmail', 'reader', null, 'stale')],
        overallStatus: 'stale',
        overflowCount: 0,
    );

    $html = view('email-scan::livewire.email-scan-health-tile', ['tile' => $tile])->render();

    expect($html)->toContain('not scanned on this phone')
        ->and($html)->not->toContain('not scanned yet');
});

it('keeps the health tile wording on a desktop', function (): void {
    $tile = new EmailScanHealthTile(
        lines: [new InboxHealthLine('gmail', 'reader', null, 'stale')],
        overallStatus: 'stale',
        overflowCount: 0,
    );

    $html = view('email-scan::livewire.email-scan-health-tile', ['tile' => $tile])->render();

    expect($html)->toContain('not scanned yet')
        ->and($html)->not->toContain('not scanned on this phone');
});

function tisnRefusingGoogleProvider(): GoogleOAuthProvider
{
    $token = new AccessTokenWithEmail(
        accessToken: 'fake-access-token',
        refreshToken: null,
        expiresAt: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        email: 'no-refresh@example.com',
    );

    return new class($token) extends GoogleOAuthProvider
    {
        public function __construct(private readonly AccessTokenWithEmail $token) {}

        public function getAuthorizationUrl(string $state, string $redirectUri): AuthorizationRequest
        {
            return new AuthorizationRequest('https://accounts.google.com/?state='.$state, '');
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

function tisnDriveRefusedCallback(User $reader): string
{
    /** @var OAuthSecretsRepository $secrets */
    $secrets = app(OAuthSecretsRepository::class);
    $secrets->saveProviderClient(
        'gmail',
        'fake-client-id.apps.googleusercontent.com',
        'GOCSPX-fake-secret',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );

    app()->instance(GoogleOAuthProvider::class, tisnRefusingGoogleProvider());

    /** @var OAuthStateRepository $stateRepo */
    $stateRepo = app(OAuthStateRepository::class);
    $state = $stateRepo->issueState('gmail', userId: $reader->id);

    test()->get('/oauth/callback/gmail?state='.$state.'&code=fake');

    $flashed = session('oauth_failed');

    return is_string($flashed) ? $flashed : '';
}

it('does not explain a refusal to a phone reader by an hourly scan the phone never runs', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    $reader = tisnUser('oauth-phone');
    $this->actingAs($reader);

    $flashed = tisnDriveRefusedCallback($reader);

    expect($flashed)->toBe(Lang::get('email-scan::inboxes.oauth_no_offline_access_google_phone'))
        ->and($flashed)->not->toContain('within the hour')
        ->and($flashed)->toContain('Publish your OAuth consent screen to production');
});

it('keeps the within-the-hour explanation on a desktop, where the hourly tick exists', function (): void {
    $reader = tisnUser('oauth-desktop');
    $this->actingAs($reader);

    $flashed = tisnDriveRefusedCallback($reader);

    expect($flashed)->toBe(Lang::get('email-scan::inboxes.oauth_no_offline_access_google'))
        ->and($flashed)->toContain('within the hour');
});
