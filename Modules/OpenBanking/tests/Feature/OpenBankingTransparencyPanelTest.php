<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// Split along the seam the screen now has: what one bank discloses is asserted
// on that bank's card, and what the reader does to the whole connector -- the
// disconnect, the second bank -- on the page the cards are listed under.

function otpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function otpSeedSecrets(User $user, string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): void
{
    OpenBankingSecretsFixture::seed($user->id, $institutionId);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function otpSeedConnection(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId(array_merge([
        'user_id' => $user->id,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function otpCard(int $connectionId): Testable
{
    return Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $connectionId]);
}

beforeEach(function (): void {
    $this->otpSeededUserIds = [];
});

afterEach(function (): void {
    foreach ($this->otpSeededUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
    }
});

it('renders aggregator, bank, consent status, whats-fetched, and ALWAYS the last-successful-sync', function (): void {
    $user = otpUser('otp-panel-fields');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    $connectionId = otpSeedConnection($user, [
        'last_successful_sync_at' => CarbonImmutable::now()->subHours(2)->toDateTimeString(),
    ]);

    otpCard($connectionId)
        ->assertSeeText('Enable Banking')
        ->assertSeeText('ASN Bank')
        ->assertSee('Booked transactions + balances, last 90 days')
        ->assertSeeHtml('data-testid="ob-consent-pill"')
        ->assertSee('Connected', escape: false)
        ->assertSeeHtml('data-testid="ob-last-successful-sync"')
        ->assertDontSeeHtml('data-testid="ob-last-attempt"');
});

// The panel is one bank's disclosure now, so the button that ends every bank's
// connection cannot live inside it -- a reader with two cards would be offered
// the same whole-connector Disconnect twice, from under one bank's name.
it('does not carry the Disconnect button inside a single bank\'s panel', function (): void {
    $user = otpUser('otp-panel-no-disconnect');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    $connectionId = otpSeedConnection($user);

    otpCard($connectionId)
        ->assertSeeHtml('data-testid="open-banking-transparency-panel"')
        ->assertDontSeeHtml('data-testid="ob-disconnect-button"');
});

// Each card discloses its own bank: the panel is fed by the connection row it
// was mounted for, never by whichever session the store happened to hold.
it('names each bank on its own card when two are connected', function (): void {
    $user = otpUser('otp-panel-two-banks');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    OpenBankingSecretsFixture::seed(
        $user->id,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        sessionId: 'fixture-session-second',
    );

    $first = otpSeedConnection($user);
    $second = otpSeedConnection($user, [
        'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-2',
    ]);

    otpCard($first)->assertSeeText('ASN Bank')->assertDontSeeText('SNS (de Volksbank)');
    otpCard($second)->assertSeeText('SNS (de Volksbank)')->assertDontSeeText('ASN Bank');

    // Both banks are named at once on the toggle line, which is the page's
    // whole-connector summary rather than any one connection's.
    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('connectionIds', [$first, $second])
        ->assertSet('connectedBanks', 'ASN Bank, SNS (de Volksbank)');
});

it('shows the last-successful-sync EVEN when the last attempt failed, and never labels it fresh from a failed attempt', function (): void {
    $user = otpUser('otp-panel-failed-attempt');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    // Captured once and used for both the seed and the assertion: recomputing
    // now() for the assertion raced the render by a clock tick and failed.
    $lastSuccessfulSync = CarbonImmutable::now()->subDays(2);

    $connectionId = otpSeedConnection($user, [
        'last_successful_sync_at' => $lastSuccessfulSync->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_status' => 'consent_failed',
    ]);

    $component = otpCard($connectionId)
        ->assertSeeHtml('data-testid="ob-last-successful-sync"')
        ->assertSeeHtml('data-testid="ob-last-attempt"')
        ->assertSee('failed (consent expired)');

    // The freshness signal reflects the OLD successful sync, never the failed attempt.
    expect($component->get('lastSuccessfulSyncAtIso'))
        ->toBe($lastSuccessfulSync->toIso8601String());
});

// "error" is what this row read before the status existed, and it sent a reader
// to check a connection, a consent and a bank that were all working.
it('names a run that filed none of its rows rather than calling it an error', function (): void {
    $user = otpUser('otp-panel-nothing-imported');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    $connectionId = otpSeedConnection($user, [
        'last_successful_sync_at' => CarbonImmutable::now()->subDays(3)->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_status' => 'nothing_imported',
    ]);

    otpCard($connectionId)
        ->assertSeeHtml('data-testid="ob-last-attempt"')
        ->assertSee('failed (nothing could be filed)')
        ->assertDontSee('failed (error)');
});

it('hides the last-attempt row when the last attempt succeeded', function (): void {
    $user = otpUser('otp-panel-ok-attempt');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    $connectionId = otpSeedConnection($user, [
        'last_successful_sync_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_status' => 'ok',
    ]);

    otpCard($connectionId)
        ->assertDontSeeHtml('data-testid="ob-last-attempt"');
});

it('disconnect() deletes this reader\'s secrets file, sets enabled=false, blanks consent_expires_at, and reverts Surface A', function (): void {
    $user = otpUser('otp-disconnect');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    $connectionId = otpSeedConnection($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->assertSeeHtml('data-testid="ob-disconnect-button"')
        ->call('startDisconnect')
        ->assertSet('showDisconnectModal', true)
        ->call('disconnect')
        ->assertSet('showDisconnectModal', false)
        ->assertSet('enabled', false)
        ->assertSet('connectionIds', []);

    expect(OpenBankingSecretsFixture::repository()->load($user->id))->toBeNull();
    expect(is_file(OpenBankingSecretsFixture::path($user->id)))->toBeFalse();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();
    expect((bool) $row->enabled)->toBeFalse();
    expect($row->consent_expires_at)->toBeNull();
});

it('the ON->OFF toggle click and the page Disconnect button reach the SAME confirm/disconnect action', function (): void {
    $viaToggle = otpUser('otp-via-toggle');
    $this->otpSeededUserIds[] = $viaToggle->id;
    $this->actingAs($viaToggle);
    otpSeedSecrets($viaToggle);
    $toggleConnectionId = otpSeedConnection($viaToggle);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->call('toggleClicked')
        ->assertSet('showDisconnectModal', true)
        ->call('disconnect')
        ->assertSet('enabled', false);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $toggleConnectionId)->value('enabled');
    expect((bool) $enabled)->toBeFalse();
});

// Two banks may now be connected, enabled and scheduled at once, and the reader
// pressing Disconnect means all of it. A row left enabled would keep syncing
// after they believe they are off.
it('disconnect() disables ALL of the reader\'s connections, not just the first', function (): void {
    $user = otpUser('otp-disconnect-all-rows');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    OpenBankingSecretsFixture::seed(
        $user->id,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        sessionId: 'fixture-session-second',
    );

    $first = otpSeedConnection($user);
    $second = otpSeedConnection($user, [
        'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-2',
        'consent_expires_at' => CarbonImmutable::now()->addDays(90)->toDateTimeString(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('connectionIds', [$first, $second])
        ->call('startDisconnect')
        ->call('disconnect')
        ->assertSet('enabled', false);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    foreach ([$first, $second] as $connectionId) {
        $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();
        expect((bool) $row->enabled)->toBeFalse();
        expect($row->consent_expires_at)->toBeNull();
    }

    expect(OpenBankingSecretsFixture::repository()->connectedInstitutions($user->id))->toBe([]);
});

// Another reader's file is a separate path, so one household member turning the
// connector off cannot take the other's banks down with it.
it('disconnect() leaves another reader\'s secrets and rows alone', function (): void {
    $leaving = otpUser('otp-disconnect-leaving');
    $staying = otpUser('otp-disconnect-staying');
    $this->otpSeededUserIds[] = $leaving->id;
    $this->otpSeededUserIds[] = $staying->id;

    otpSeedSecrets($leaving);
    otpSeedSecrets($staying);
    $stayingConnectionId = otpSeedConnection($staying);
    otpSeedConnection($leaving);

    $this->actingAs($leaving);
    Livewire::test(OpenBankingSettingsPage::class)
        ->call('startDisconnect')
        ->call('disconnect')
        ->assertSet('enabled', false);

    expect(OpenBankingSecretsFixture::repository()->connectedInstitutions($staying->id))
        ->toBe([OpenBankingSecretsFixture::INSTITUTION_ID]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $stayingConnectionId)->first();
    expect((bool) $row->enabled)->toBeTrue();
    expect($row->consent_expires_at)->not->toBeNull();
});

it('cancelDisconnect closes the modal without disconnecting', function (): void {
    $user = otpUser('otp-cancel-disconnect');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    $connectionId = otpSeedConnection($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('startDisconnect')
        ->call('cancelDisconnect')
        ->assertSet('showDisconnectModal', false)
        ->assertSet('enabled', true);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $enabled = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('enabled');
    expect((bool) $enabled)->toBeTrue();
});

// Adding a bank re-acknowledges nothing: the third-party warning was answered
// when the first one was linked, so this opens straight at the picker.
it('the Connect another bank button opens the wizard at the bank step', function (): void {
    $user = otpUser('otp-connect-another');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);
    otpSeedSecrets($user);
    otpSeedConnection($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeHtml('data-testid="ob-connect-another-button"')
        ->assertSee('Connect another bank')
        ->call('connectAnotherBank')
        ->assertDispatched('open-banking-wizard:open', startStep: 4);
});

it('offers no Connect another bank button while no bank is connected', function (): void {
    $user = otpUser('otp-connect-another-off');
    $this->otpSeededUserIds[] = $user->id;
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', false)
        ->assertDontSeeHtml('data-testid="ob-connect-another-button"')
        ->assertDontSeeHtml('data-testid="ob-disconnect-button"');
});
