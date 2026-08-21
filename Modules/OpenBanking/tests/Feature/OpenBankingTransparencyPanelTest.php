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

uses(RefreshDatabase::class);

function otpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function otpSeedSecrets(string $institutionId = 'ASNBNL21'): void
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
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
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

it('renders aggregator, bank, consent status, whats-fetched, and ALWAYS the last-successful-sync', function (): void {
    $user = otpUser('otp-panel-fields');
    $this->actingAs($user);
    otpSeedSecrets();
    otpSeedConnection($user, [
        'last_successful_sync_at' => CarbonImmutable::now()->subHours(2)->toDateTimeString(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeText('Enable Banking')
        ->assertSeeText('ASN Bank')
        ->assertSee('Booked transactions + balances, last 90 days')
        ->assertSeeHtml('data-testid="ob-consent-pill"')
        ->assertSee('Connected', escape: false)
        ->assertSeeHtml('data-testid="ob-last-successful-sync"')
        ->assertDontSeeHtml('data-testid="ob-last-attempt"');
});

it('shows the last-successful-sync EVEN when the last attempt failed, and never labels it fresh from a failed attempt', function (): void {
    $user = otpUser('otp-panel-failed-attempt');
    $this->actingAs($user);
    otpSeedSecrets();
    // Captured once and used for both the seed and the assertion: recomputing
    // now() for the assertion raced the render by a clock tick and failed.
    $lastSuccessfulSync = CarbonImmutable::now()->subDays(2);

    otpSeedConnection($user, [
        'last_successful_sync_at' => $lastSuccessfulSync->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_status' => 'consent_failed',
    ]);

    $component = Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeHtml('data-testid="ob-last-successful-sync"')
        ->assertSeeHtml('data-testid="ob-last-attempt"')
        ->assertSee('failed (consent expired)');

    // The freshness signal reflects the OLD successful sync, never the failed attempt.
    expect($component->get('lastSuccessfulSyncAtIso'))
        ->toBe($lastSuccessfulSync->toIso8601String());
});

it('hides the last-attempt row when the last attempt succeeded', function (): void {
    $user = otpUser('otp-panel-ok-attempt');
    $this->actingAs($user);
    otpSeedSecrets();
    otpSeedConnection($user, [
        'last_successful_sync_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        'last_attempt_status' => 'ok',
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertDontSeeHtml('data-testid="ob-last-attempt"');
});

it('disconnect() clears the secrets file, sets enabled=false, blanks consent_expires_at, and reverts Surface A', function (): void {
    $user = otpUser('otp-disconnect');
    $this->actingAs($user);
    otpSeedSecrets();
    $connectionId = otpSeedConnection($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->call('startDisconnect')
        ->assertSet('showDisconnectModal', true)
        ->call('disconnect')
        ->assertSet('showDisconnectModal', false)
        ->assertSet('enabled', false);

    $secrets = app(OpenBankingSecretsRepository::class);
    expect($secrets->load())->toBeNull();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();
    expect((bool) $row->enabled)->toBeFalse();
    expect($row->consent_expires_at)->toBeNull();
});

it('the ON->OFF toggle click and the panel Disconnect button reach the SAME confirm/disconnect action', function (): void {
    $viaToggle = otpUser('otp-via-toggle');
    $this->actingAs($viaToggle);
    otpSeedSecrets();
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

// open_banking_connections has no unique constraint, so a second bank linked
// without disconnecting the first leaves an enabled row the connection query
// never shows but daily-sync still picks up. disconnect() must flip every row.
it('disconnect() disables ALL of the user\'s connections, not just the one currently displayed', function (): void {
    $user = otpUser('otp-disconnect-all-rows');
    $this->actingAs($user);
    otpSeedSecrets('ASNBNL21');
    $displayedConnectionId = otpSeedConnection($user, ['institution_id' => 'ASNBNL21']);
    $orphanedConnectionId = otpSeedConnection($user, [
        'institution_id' => 'SNSBNL21',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(90)->toDateTimeString(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('connectionId', $displayedConnectionId)
        ->call('startDisconnect')
        ->call('disconnect')
        ->assertSet('enabled', false);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $orphanedRow = $db->connection()->table('open_banking_connections')->where('id', $orphanedConnectionId)->first();
    expect((bool) $orphanedRow->enabled)->toBeFalse();
    expect($orphanedRow->consent_expires_at)->toBeNull();

    $displayedRow = $db->connection()->table('open_banking_connections')->where('id', $displayedConnectionId)->first();
    expect((bool) $displayedRow->enabled)->toBeFalse();
    expect($displayedRow->consent_expires_at)->toBeNull();
});

it('cancelDisconnect closes the modal without disconnecting', function (): void {
    $user = otpUser('otp-cancel-disconnect');
    $this->actingAs($user);
    otpSeedSecrets();
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
