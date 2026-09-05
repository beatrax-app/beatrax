<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Public\Http\Livewire\OpenBankingStatusRow;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

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
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => null,
        'enabled' => false,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function owgSeedInstitutionSecrets(User $user, string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): void
{
    OpenBankingSecretsFixture::seed($user->id, $institutionId);
}

function owgEnabled(int $connectionId): bool
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (bool) $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
}

beforeEach(function (): void {
    $this->owgSeededUserIds = [];
});

afterEach(function (): void {
    foreach ($this->owgSeededUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
    }
    CarbonImmutable::setTestNow();
});

it('fresh install: OB is off, no connection row, toggle renders off', function (): void {
    $user = owgUser('owg-fresh');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false)
        ->assertSet('connectionIds', [])
        ->assertSet('connectedBanks', '')
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

    expect(owgEnabled($connectionId))->toBeFalse();
});

it('the full acknowledged path enables OB', function (): void {
    $user = owgUser('owg-direct-ack');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
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

    expect(owgEnabled($connectionId))->toBeTrue();

    // Single-use: the flag must not survive to authorize a second flip.
    expect(session('open_banking_acknowledged'))->toBeNull();
});

// Two banks may be connected, enabled and scheduled at once, so finishing
// consent at the second one must not stand the first one down. Standing the
// others down is what made the second bank the only usable one.
it('enabling the bank just consented to leaves an already-connected bank running', function (): void {
    $user = owgUser('owg-enable-second-bank');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
    OpenBankingSecretsFixture::seed(
        $user->id,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        sessionId: 'fixture-session-second',
    );

    $liveId = owgSeedConnection($user, ['enabled' => true]);
    $pendingId = owgSeedConnection($user, [
        'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-2',
    ]);

    session([
        'open_banking_connected' => $pendingId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->assertSet('connectionIds', [$liveId, $pendingId])
        ->assertSet('connectedBanks', 'ASN Bank, SNS (de Volksbank)');

    expect(owgEnabled($liveId))->toBeTrue();
    expect(owgEnabled($pendingId))->toBeTrue();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('open_banking_connections')->where('id', $liveId)->value('consent_expires_at'))
        ->not->toBeNull();
});

// The enable is one UPDATE over one row, so a refusal leaves the reader exactly
// where they were: nothing enabled, and the pending row still waiting.
it('leaves the pending row off when the enabling UPDATE is refused', function (): void {
    $user = owgUser('owg-enable-refused');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
    $pendingId = owgSeedConnection($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
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

    expect(owgEnabled($pendingId))->toBeFalse();
});

it('a post-callback mount with a pending connection but a STALE ack sets needsReconfirm and leaves OB off', function (): void {
    $user = owgUser('owg-reconfirm-stale');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
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

    expect(owgEnabled($connectionId))->toBeFalse();
});

it('reconfirmEnable re-mints a fresh ack and completes the enable', function (): void {
    $user = owgUser('owg-reconfirm-completes');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
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

    expect(owgEnabled($connectionId))->toBeTrue();

    // Single-use: the re-minted ack is consumed by the successful enable.
    expect(session('open_banking_acknowledged'))->toBeNull();
});

it('a fresh ack within the 2-hour TTL still finalizes at mount without needing re-confirm', function (): void {
    $user = owgUser('owg-reconfirm-not-needed');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
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
    $this->owgSeededUserIds[] = $owner->id;
    $this->owgSeededUserIds[] = $attacker->id;
    owgSeedInstitutionSecrets($owner);
    owgSeedInstitutionSecrets($attacker);
    $connectionId = owgSeedConnection($owner);

    $this->actingAs($attacker);
    // Even if a foreign connection id reached the attacker's server-set redirect
    // flash, enableOpenBanking()'s user_id predicate blocks the cross-user flip.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class);

    expect(owgEnabled($connectionId))->toBeFalse();
});

it('mount auto-finalizes the enable when both the redirect flash and the session ack are present', function (): void {
    $user = owgUser('owg-mount-finalize');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
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
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
    $connectionId = owgSeedConnection($user);

    // 121 minutes old — one minute past the two-hour TTL.
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->subMinutes(121)->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false);

    expect(owgEnabled($connectionId))->toBeFalse();
});

it('a non-integer session ack value (e.g. a legacy boolean true) does not authorize enableOpenBanking', function (): void {
    $user = owgUser('owg-legacy-boolean-ack');
    $this->owgSeededUserIds[] = $user->id;
    $this->actingAs($user);
    owgSeedInstitutionSecrets($user);
    $connectionId = owgSeedConnection($user);

    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => true,
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false);

    expect(owgEnabled($connectionId))->toBeFalse();
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
    $this->owgSeededUserIds[] = $connected->id;
    $this->actingAs($connected);
    owgSeedInstitutionSecrets($connected);
    owgSeedConnection($connected, [
        'enabled' => true,
        'last_successful_sync_at' => CarbonImmutable::now()->subHours(2)->toDateTimeString(),
    ]);
    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', false)
        ->assertSeeText('Connected to ASN Bank via Enable Banking.');

    $expired = owgUser('owg-status-expired');
    $this->owgSeededUserIds[] = $expired->id;
    $this->actingAs($expired);
    owgSeedInstitutionSecrets($expired);
    owgSeedConnection($expired, [
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->subDay()->toDateTimeString(),
    ]);
    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', true)
        ->assertSee('Consent expired — reconnect needed.');
});
