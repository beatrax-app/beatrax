<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Tests\Support\AfnStubRemoteSourceAdapter;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// A window the bank had nothing in and a window whose every row was refused are
// the same thing to a queue: no exception, no failed job, nothing written. On a
// phone the second one repeats daily forever, because the process that ticks
// never holds the app-lock key the sealed columns need.

const AFN_OWN_IBAN = 'NL57ASNB0123456789';

const AFN_ALERT_KIND = 'open_banking_nothing_imported';

function afnSeedCredentials(): void
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

function afnSeedConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-afn-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function afnConnectionRow(int $connectionId): stdClass
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var stdClass $row */
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    return $row;
}

function afnAlertCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('system_alerts')
        ->where('user_id', $userId)
        ->where('kind', AFN_ALERT_KIND)
        ->count();
}

// The precondition the phone is permanently in: encryption is on for this user
// and the process running the tick holds no key, so every registered column the
// import writes is refused and every row comes back an error row.
function afnWithholdTheKey(): void
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::lock($session);
}

beforeEach(function (): void {
    $this->secretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->connectionId = afnSeedConnection($this->fixtureUser);
    afnSeedCredentials();

    $this->adapter = new AfnStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $this->adapter);
});

afterEach(function (): void {
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow();
});

it('names the attempt for what it was when the bank sent rows and not one of them could be filed', function (): void {
    $this->enablesEncryptionForUser($this->fixtureUser);
    afnWithholdTheKey();

    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect($this->adapter->fetches)->toBe(1);
    expect(Transaction::query()->count())->toBe(0);

    /** @var ImportRun $run */
    $run = ImportRun::query()->where('user_id', $this->fixtureUser->id)->latest('id')->firstOrFail();
    expect($run->status)->not->toBe(ImportRunStatus::Confirmed->value);

    $connection = afnConnectionRow($this->connectionId);
    expect($connection->last_successful_sync_at)->toBeNull();
    expect($connection->fetched_through_at)->toBeNull();
    expect($connection->last_attempt_status)->toBe(SyncAttemptStatus::NothingImported->value);
});

// The job does not fail — retrying an import the same keyless worker will refuse
// again is pointless — so the alert is the only thing that reaches the reader.
it('raises one standing alert for a feed that fetches and files nothing, however many times it ticks', function (): void {
    $this->enablesEncryptionForUser($this->fixtureUser);
    afnWithholdTheKey();

    for ($tick = 0; $tick < 3; $tick++) {
        app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);
    }

    expect($this->adapter->fetches)->toBe(3);
    expect(afnAlertCount((int) $this->fixtureUser->id))->toBe(1);
});

// The trap. A quiet week is the ordinary outcome of a daily sync and must stay
// quiet: no alert, a moved freshness signal, and a cursor that advances.
it('leaves a window the bank had nothing in exactly as it was: successful, silent, cursor advanced', function (): void {
    app()->instance(RemoteSourceAdapter::class, new AfnStubRemoteSourceAdapter(rowCount: 0));

    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    $connection = afnConnectionRow($this->connectionId);
    expect($connection->last_attempt_status)->toBe(SyncAttemptStatus::Ok->value);
    expect($connection->last_successful_sync_at)->not->toBeNull();
    expect($connection->fetched_through_at)->not->toBeNull();
    expect(afnAlertCount((int) $this->fixtureUser->id))->toBe(0);
});

// The same refusal on the button the reader presses. "No new transactions." is
// the sentence a quiet week earns, and it was being handed to a press that
// fetched rows and filed none of them.
it('does not tell a reader who pressed Sync now that there was nothing new', function (): void {
    $this->actingAs($this->fixtureUser);
    $this->enablesEncryptionForUser($this->fixtureUser);
    afnWithholdTheKey();

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSet('syncFlashMessage', 'Your bank sent transactions, but none of them could be filed. Open the import review to see why.')
        // The link was gated on the success tone, so the two flashes that most
        // need it — this one and a truncated walk — could never render it.
        ->assertSeeHtml('data-testid="ob-review-import-link"');
});

// The alert row exists to be read, and the banner renders a known kind from the
// reader's own locale rather than from the copy frozen into the message column.
it('says on every screen what the connection row only says on one', function (): void {
    $this->actingAs($this->fixtureUser);
    $this->enablesEncryptionForUser($this->fixtureUser);
    afnWithholdTheKey();

    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    Livewire::test(SystemAlertsBanner::class)
        ->assertSee('Your bank sent transactions, but Beatrax could not file any of them');
});
