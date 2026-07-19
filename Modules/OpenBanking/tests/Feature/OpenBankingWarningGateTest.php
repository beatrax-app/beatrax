<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingStatusRow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

/*
 * 19-11 Task 1: the off-by-default toggle + B2 loud-warning gate + Surface
 * A entry row. The load-bearing scenario (Req 4 / RESEARCH.md Pitfall 7):
 * `enableOpenBanking()` — the ONE sink that ever sets
 * `open_banking_connections.enabled = true` — is called DIRECTLY,
 * bypassing the wizard's client-side sequencing entirely, and proven to be
 * a structural no-op without the session-persisted acknowledgement flag.
 */

function owgUser(string $username): User
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
function owgSeedConnection(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId(array_merge([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => null,
        'enabled' => false,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

it('fresh install: OB is off, no connection row, toggle renders off', function (): void {
    $user = owgUser('owg-fresh');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false)
        ->assertSet('consentStatus', 'off')
        ->assertSeeHtml('data-testid="ob-toggle"')
        ->assertDontSeeHtml('switch--on');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);
});

it('requestEnable opens the B2 modal without flipping the toggle', function (): void {
    $user = owgUser('owg-request-enable');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('showWarningModal', false)
        ->call('requestEnable')
        ->assertSet('showWarningModal', true)
        ->assertSet('acknowledged', false)
        ->assertSet('enabled', false);
});

it('confirmWarning without the checkbox checked does nothing', function (): void {
    $user = owgUser('owg-confirm-unchecked');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('requestEnable')
        ->set('acknowledged', false)
        ->call('confirmWarning')
        ->assertSet('showWarningModal', true);

    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('confirmWarning with the checkbox checked persists a fresh session-ack timestamp and opens the wizard', function (): void {
    $user = owgUser('owg-confirm-checked');
    $this->actingAs($user);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:00:00'));

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('requestEnable')
        ->set('acknowledged', true)
        ->call('confirmWarning')
        ->assertSet('showWarningModal', false)
        ->assertSet('acknowledged', false)
        ->assertDispatched('open-banking-wizard:open');

    expect(session('open_banking_acknowledged'))->toBe(CarbonImmutable::parse('2026-07-19 12:00:00')->getTimestamp());

    CarbonImmutable::setTestNow();
});

it('a redirect flash WITHOUT acknowledgement leaves OB off (Req 4 server-side proof)', function (): void {
    $user = owgUser('owg-direct-no-ack');
    $this->actingAs($user);
    $connectionId = owgSeedConnection($user);

    // WR-05: pendingConnectionId is #[Locked] — it can only be populated
    // by the server-set redirect flash, never a client ->set(). mount()
    // consumes the flash and runs enableOpenBanking(), which no-ops here
    // because no session acknowledgement is present.
    session(['open_banking_connected' => $connectionId]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeFalse();
});

it('the full acknowledged path enables OB', function (): void {
    $user = owgUser('owg-direct-ack');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    // WR-05: drive the enable through the legitimate server path — the
    // redirect flash (server-set) + a fresh session ack. mount() consumes
    // both and finalizes the enable.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('pendingConnectionId', null)
        ->assertSet('enabled', true);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeTrue();

    // Single-use: the flag must not survive to authorize a second flip.
    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('enableOpenBanking cannot flip a DIFFERENT user\'s connection', function (): void {
    $owner = owgUser('owg-owner');
    $attacker = owgUser('owg-attacker');
    $connectionId = owgSeedConnection($owner);

    $this->actingAs($attacker);
    // Even if a foreign connection id somehow reaches the attacker's
    // server-set redirect flash, enableOpenBanking()'s user_id predicate
    // blocks the cross-user flip (pendingConnectionId is #[Locked], so a
    // client ->set() is no longer even possible — WR-05).
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeFalse();
});

it('mount auto-finalizes the enable when both the redirect flash and the session ack are present', function (): void {
    $user = owgUser('owg-mount-finalize');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->assertSet('pendingConnectionId', null);
});

/*
 * D-16 Wave 3 review-and-fix gate (19-14): a STALE session-ack timestamp
 * (older than enableOpenBanking()'s TTL) must not authorize a flip, even
 * though it satisfies a naive "is the flag present" check. This is the
 * server-side proof that the acknowledgement itself expires rather than
 * remaining a standing authorization for the lifetime of the session —
 * closing the gap where an abandoned (never explicitly cancelled) wizard
 * tab could otherwise leave a live "enable" token sitting in session
 * indefinitely.
 */
it('a STALE session ack (older than the TTL) does not authorize enableOpenBanking (Req 4 hardening)', function (): void {
    $user = owgUser('owg-stale-ack');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    // 31 minutes old — one minute past the 30-minute TTL.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->subMinutes(31)->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeFalse();
});

it('a non-integer session ack value (e.g. a legacy boolean true) does not authorize enableOpenBanking', function (): void {
    $user = owgUser('owg-legacy-boolean-ack');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => true,
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeFalse();
});

it('the B2 modal carries the exact UI-SPEC copy, alertdialog role, and 2px rose border', function (): void {
    $user = owgUser('owg-modal-copy');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('requestEnable')
        ->assertSeeHtml('role="alertdialog"')
        ->assertSeeHtml('border-2 border-rose-300')
        ->assertSee('Before you connect a third party')
        ->assertSee('I understand my transaction data will be shared with Enable Banking and my bank.')
        ->assertSee('Enable open banking');
});

it('Surface A status row renders the correct one-line status for each state', function (): void {
    $off = owgUser('owg-status-off');
    $this->actingAs($off);
    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', false)
        ->assertSee('Not connected. Import ICS/ASN statements manually, or connect a bank automatically.');

    $connected = owgUser('owg-status-connected');
    $this->actingAs($connected);
    owgSeedInstitutionSecrets('ASNBNL21');
    owgSeedConnection($connected, [
        'enabled' => true,
        'last_successful_sync_at' => CarbonImmutable::now()->subHours(2)->toDateTimeString(),
    ]);
    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', false)
        ->assertSeeText('Connected to ASN Bank via Enable Banking.');

    $expired = owgUser('owg-status-expired');
    $this->actingAs($expired);
    owgSeedInstitutionSecrets('ASNBNL21');
    owgSeedConnection($expired, [
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->subDay()->toDateTimeString(),
    ]);
    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', true)
        ->assertSee('Consent expired — reconnect needed.');
});

/**
 * Seeds the ONE global secrets-file session `OpenBankingConnectionQuery`
 * resolves the active connection from (see that class's docblock).
 */
function owgSeedInstitutionSecrets(string $institutionId): void
{
    $path = storage_path('app/secrets/open-banking.json');
    if (is_file($path)) {
        @unlink($path);
    }

    /** @var OpenBankingSecretsRepository $repo */
    $repo = app(OpenBankingSecretsRepository::class);
    $repo->save(new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: 'fixture-pem',
        sessionId: 'fixture-session',
        consentExpiresAt: CarbonImmutable::now()->addDays(180),
        bankScaHost: 'sca.asnbank.example',
        institutionId: $institutionId,
    ));
}

afterEach(function (): void {
    $path = storage_path('app/secrets/open-banking.json');
    if (is_file($path)) {
        @unlink($path);
    }
    if (is_file($path.'.tmp')) {
        @unlink($path.'.tmp');
    }
});
