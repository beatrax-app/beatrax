<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

/*
 * Manual "Sync now" action (19-12 Task 2, UI-SPEC Surface B6, Req 9):
 * `OpenBankingFetchService::preview()` (never `fetchAndConfirm()` — success
 * routes to the EXISTING consolidated import-preview page for the user to
 * review, this button never auto-commits). Two-timestamp accounting mirrors
 * `SyncOpenBankingAccountJob` exactly: a successful fetch (new rows or zero
 * rows) advances `last_successful_sync_at`; a failed fetch advances ONLY
 * `last_attempt_*`, never `last_successful_sync_at` (Req 7's
 * never-stale-as-fresh invariant).
 */

final class OmsStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    /**
     * @param  list<SourceTransactionDto>  $rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?Throwable $throws = null,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        yield from $this->rows;
    }
}

function omsSeedCredentials(string $institutionId = 'ASNBNL21'): void
{
    $path = storage_path('app/secrets/open-banking.json');
    if (is_file($path)) {
        @unlink($path);
    }

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
        bankScaHost: 'sca.asnbank.example',
        institutionId: $institutionId,
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function omsSeedConnection(User $user, array $overrides = []): int
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

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));
});

afterEach(function (): void {
    $path = storage_path('app/secrets/open-banking.json');
    if (is_file($path)) {
        @unlink($path);
    }
    if (is_file($path.'.tmp')) {
        @unlink($path.'.tmp');
    }
    CarbonImmutable::setTestNow();
});

it('an eligible connection: syncNow surfaces "N new transactions found." with a working Review import link, and advances last_successful_sync_at', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->actingAs($user);

    omsSeedCredentials();
    $connectionId = omsSeedConnection($user);

    $newRow = new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse('2026-07-18'),
        postedAt: CarbonImmutable::parse('2026-07-18'),
        valueDate: CarbonImmutable::parse('2026-07-18'),
        ownIban: 'NL57ASNB0123456789',
        counterpartyIban: 'NL00RABO0123456789',
        counterpartyName: 'Fixture Merchant',
        currency: 'EUR',
        amountMinor: -1099,
        sourceRef: 'oms-fixture-ref-1',
        description: 'Fixture EB row',
        rawPayload: [],
        sourceRowIndex: 0,
    );
    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([$newRow]));

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'success')
        ->assertSet('syncFlashMessage', '1 new transactions found.')
        ->assertSeeHtml('data-testid="ob-review-import-link"');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->not->toBeNull();
    expect($row->last_attempt_status)->toBe('ok');
});

it('an eligible connection with zero new rows: "No new transactions." and last_successful_sync_at still advances', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->actingAs($user);

    omsSeedCredentials();
    $connectionId = omsSeedConnection($user);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([]));

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'zero')
        ->assertSet('syncFlashMessage', 'No new transactions.');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->not->toBeNull();
});

it('a disabled connection: syncNow is a no-op — no fetch, no timestamp write, no flash', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->actingAs($user);

    omsSeedCredentials();
    $connectionId = omsSeedConnection($user, ['enabled' => false]);

    $stub = new OmsStubRemoteSourceAdapter([]);
    app()->instance(RemoteSourceAdapter::class, $stub);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertDontSeeHtml('data-testid="open-banking-sync-now"')
        ->call('syncNow')
        ->assertSet('syncFlashMessage', '')
        ->assertSet('syncFlashTone', '');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_attempt_at)->toBeNull();
    expect($row->last_successful_sync_at)->toBeNull();
});

it('an expired-consent connection: the button is disabled with "Reconnect first", and syncNow is a no-op', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->actingAs($user);

    omsSeedCredentials();
    $connectionId = omsSeedConnection($user, [
        'consent_expires_at' => CarbonImmutable::parse('2026-01-01 00:00:00')->toDateTimeString(),
    ]);

    $stub = new OmsStubRemoteSourceAdapter([]);
    app()->instance(RemoteSourceAdapter::class, $stub);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeHtml('data-testid="ob-sync-now-disabled-caption"')
        ->assertSee('Reconnect first')
        ->call('syncNow')
        ->assertSet('syncFlashMessage', '')
        ->assertSet('syncFlashTone', '');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_attempt_at)->toBeNull();
});

it('a generic fetch failure: rose flash, last_attempt_status=error, and last_successful_sync_at stays UNCHANGED', function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->actingAs($user);

    omsSeedCredentials();
    $priorSuccess = CarbonImmutable::parse('2026-07-18 06:00:00')->toDateTimeString();
    $connectionId = omsSeedConnection($user, ['last_successful_sync_at' => $priorSuccess]);

    $stub = new OmsStubRemoteSourceAdapter(
        throws: EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 500, 'server error')
    );
    app()->instance(RemoteSourceAdapter::class, $stub);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSet('syncFlashMessage', 'Enable Banking is temporarily unavailable. Try again shortly.');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    expect($row->last_successful_sync_at)->toBe($priorSuccess);
    expect($row->last_attempt_status)->toBe('error');
});

it('a consent failure (HTTP 401): rose flash with the specific reason, marks consent_failed, and dispatches OpenBankingConsentFailed', function (): void {
    Event::fake([OpenBankingConsentFailed::class]);

    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->actingAs($user);

    omsSeedCredentials();
    $connectionId = omsSeedConnection($user);

    $stub = new OmsStubRemoteSourceAdapter(
        throws: EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 401, 'unauthorized')
    );
    app()->instance(RemoteSourceAdapter::class, $stub);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSet('syncFlashMessage', 'Consent expired — reconnect.');

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
