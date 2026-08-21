<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

function orfUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function orfSeedSecrets(string $institutionId = 'ASNBNL21'): void
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
        consentExpiresAt: CarbonImmutable::now()->subDay(),
        bankScaHost: 'sca.asnbank.example',
        institutionId: $institutionId,
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function orfSeedConnection(User $user, array $overrides = []): int
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
        'consent_expires_at' => CarbonImmutable::now()->subDay()->toDateTimeString(),
        'last_successful_sync_at' => CarbonImmutable::now()->subDays(5)->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subDay()->toDateTimeString(),
        'last_attempt_status' => 'consent_failed',
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

it('renders the expired-consent banner referencing the last successful sync, with a Reconnect CTA', function (): void {
    $user = orfUser('orf-banner');
    $this->actingAs($user);
    orfSeedSecrets();
    orfSeedConnection($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('consentStatus', 'expired')
        ->assertSeeHtml('data-testid="open-banking-consent-expired-banner"')
        ->assertSeeHtml('role="alert"')
        ->assertSee('Consent expired — reconnect')
        ->assertSeeHtml('data-testid="ob-reconnect-button"');
});

it('does NOT render the expired-consent banner while consent is still valid', function (): void {
    $user = orfUser('orf-no-banner');
    $this->actingAs($user);
    orfSeedSecrets();
    orfSeedConnection($user, [
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'last_attempt_status' => 'ok',
    ]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('consentStatus', 'connected')
        ->assertDontSeeHtml('data-testid="open-banking-consent-expired-banner"');
});

it('reconnect() dispatches the wizard at Step 4 with the bank pre-selected, WITHOUT touching last_successful_sync_at', function (): void {
    $user = orfUser('orf-reconnect');
    $this->actingAs($user);
    orfSeedSecrets('ASNBNL21');
    $connectionId = orfSeedConnection($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $before = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('last_successful_sync_at');

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('reconnect')
        ->assertDispatched('open-banking-wizard:open', startStep: 4, bankChoice: 'asn', otherInstitutionId: '');

    $after = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('last_successful_sync_at');
    expect($after)->toBe($before);
});

it('reconnect() pre-selects "other" with the raw institution id for a non-ASN/SNS connection', function (): void {
    $user = orfUser('orf-reconnect-other');
    $this->actingAs($user);
    orfSeedSecrets('SOMEBANKXXX');
    orfSeedConnection($user, ['institution_id' => 'SOMEBANKXXX']);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('reconnect')
        ->assertDispatched('open-banking-wizard:open', startStep: 4, bankChoice: 'other', otherInstitutionId: 'SOMEBANKXXX');
});

it('stale data is never labeled fresh: the panel Last-successful-sync stays put across an expired-then-relinked cycle until an actual fetch succeeds', function (): void {
    $user = orfUser('orf-never-stale');
    $this->actingAs($user);
    orfSeedSecrets('ASNBNL21');
    $connectionId = orfSeedConnection($user);

    $component = Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('consentStatus', 'expired');

    $originalLastSync = $component->get('lastSuccessfulSyncAtIso');

    // The re-link branch restores consent without touching
    // last_successful_sync_at; only a successful sync advances that column.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('open_banking_connections')
        ->where('id', $connectionId)
        ->update(['consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString()]);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('consentStatus', 'connected')
        ->assertSet('lastSuccessfulSyncAtIso', $originalLastSync)
        ->assertDontSeeHtml('data-testid="open-banking-consent-expired-banner"');
});
