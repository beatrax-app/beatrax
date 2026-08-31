<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Http\Livewire\OpenBankingStatusRow;

uses(RefreshDatabase::class);

// enableOpenBanking() is the one sink that sets enabled = true. It is called
// directly, bypassing the wizard, to prove it no-ops without a session ack.
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

it('confirmWarning WITHOUT a prior requestEnable is a no-op even with a forged acknowledged flag', function (): void {
    $user = owgUser('owg-forged-ack-no-request');
    $this->actingAs($user);

    // A crafted client can set the wire:model.live $acknowledged property, but
    // never the #[Locked] $warningShown flag, so the server-side gate stays shut.
    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('warningShown', false)
        ->set('acknowledged', true)
        ->call('confirmWarning')
        ->assertNotDispatched('open-banking-wizard:open');

    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('the full requestEnable -> check -> confirmWarning path still works and consumes warningShown', function (): void {
    $user = owgUser('owg-warning-shown-happy');
    $this->actingAs($user);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:00:00'));

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('requestEnable')
        ->assertSet('warningShown', true)
        ->set('acknowledged', true)
        ->call('confirmWarning')
        ->assertSet('warningShown', false)
        ->assertDispatched('open-banking-wizard:open');

    expect(session('open_banking_acknowledged'))->toBe(CarbonImmutable::parse('2026-07-19 12:00:00')->getTimestamp());

    CarbonImmutable::setTestNow();
});

it('cancelWarning revokes the warningShown proof', function (): void {
    $user = owgUser('owg-cancel-revokes-proof');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('requestEnable')
        ->assertSet('warningShown', true)
        ->call('cancelWarning')
        ->assertSet('warningShown', false)
        // A confirm after cancel — even with the checkbox forged true —
        // must not proceed without a fresh requestEnable().
        ->set('acknowledged', true)
        ->call('confirmWarning')
        ->assertNotDispatched('open-banking-wizard:open');

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

it('a redirect flash WITHOUT acknowledgement leaves OB off', function (): void {
    $user = owgUser('owg-direct-no-ack');
    $this->actingAs($user);
    $connectionId = owgSeedConnection($user);

    // pendingConnectionId is #[Locked], so only the server-set redirect flash
    // populates it; mount() consumes it but no-ops without a session ack.
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

    // The legitimate server path: the server-set redirect flash plus a fresh
    // session ack, both consumed by mount().
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

// Two UPDATEs, one invariant. Standing the other rows down and then failing to
// stand this one up satisfies "one live connection" by having none — every row
// disabled with its consent blanked, and nothing enabled.
it('leaves every row as it found them when the enabling half of the flip fails', function (): void {
    $user = owgUser('owg-enable-atomic');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');

    $liveId = owgSeedConnection($user, ['institution_id' => 'ASNBNL21', 'enabled' => true]);
    $pendingId = owgSeedConnection($user, ['institution_id' => 'SNSBNL21']);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    // Fires only on the enabling UPDATE: the stand-down half moves rows the
    // other way, from enabled to disabled.
    $db->connection()->statement(
        'CREATE TRIGGER owg_refuse_enable BEFORE UPDATE ON open_banking_connections '
        ."WHEN NEW.enabled = 1 AND OLD.enabled = 0 BEGIN SELECT RAISE(ABORT, 'fixture: enable refused'); END"
    );

    session([
        'open_banking_connected' => $pendingId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    $thrown = null;
    try {
        Livewire::test(OpenBankingSettingsPage::class);
    } catch (Throwable $e) {
        // Livewire renders inside mount(), so the query failure arrives
        // wrapped; the trigger's own message is what identifies it.
        $thrown = $e;
    } finally {
        $db->connection()->statement('DROP TRIGGER owg_refuse_enable');
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('fixture: enable refused');

    $liveRow = $db->connection()->table('open_banking_connections')->where('id', $liveId)->first();
    expect((bool) $liveRow->enabled)->toBeTrue()
        ->and($liveRow->consent_expires_at)->not->toBeNull();

    $pendingRow = $db->connection()->table('open_banking_connections')->where('id', $pendingId)->first();
    expect((bool) $pendingRow->enabled)->toBeFalse();
});

it('a post-callback mount with a pending connection but a STALE ack sets needsReconfirm and leaves OB off', function (): void {
    $user = owgUser('owg-reconfirm-stale');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    // Consent completed, but the ack is 3 hours old — past the 2-hour TTL.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->subHours(3)->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false)
        ->assertSet('needsReconfirm', true)
        ->assertSeeHtml('data-testid="ob-reconfirm-button"');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeFalse();
});

it('reconfirmEnable re-mints a fresh ack and completes the enable', function (): void {
    $user = owgUser('owg-reconfirm-completes');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->subHours(3)->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('needsReconfirm', true)
        ->call('reconfirmEnable')
        ->assertSet('needsReconfirm', false)
        ->assertSet('enabled', true)
        ->assertSet('pendingConnectionId', null);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeTrue();

    // Single-use: the re-minted ack is consumed by the successful enable.
    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('a fresh ack within the 2-hour TTL still finalizes at mount without needing re-confirm', function (): void {
    $user = owgUser('owg-reconfirm-not-needed');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    // 90 minutes old — inside the 2-hour TTL, so it finalizes silently.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->subMinutes(90)->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->assertSet('needsReconfirm', false);
});

it('enableOpenBanking cannot flip a DIFFERENT user\'s connection', function (): void {
    $owner = owgUser('owg-owner');
    $attacker = owgUser('owg-attacker');
    $connectionId = owgSeedConnection($owner);

    $this->actingAs($attacker);
    // Even if a foreign connection id reached the attacker's server-set redirect
    // flash, enableOpenBanking()'s user_id predicate blocks the cross-user flip.
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

// A stale ack passes a naive "is the flag present" check, so an abandoned
// wizard tab would otherwise leave a live enable token for the whole session.
it('a STALE session ack (older than the TTL) does not authorize enableOpenBanking', function (): void {
    $user = owgUser('owg-stale-ack');
    $this->actingAs($user);
    owgSeedInstitutionSecrets('ASNBNL21');
    $connectionId = owgSeedConnection($user);

    // 121 minutes old — one minute past the two-hour TTL.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->subMinutes(121)->getTimestamp(),
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
        ->assertSee('No bank connected. Connect one to import transactions automatically.');

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
