<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// The expired-consent banner and its Reconnect button belong to one bank, so
// they are asserted on that bank's card. A reader with two connected banks has
// one lapsed consent, not a lapsed page.

function orfUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function orfSeedSecrets(User $user, string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): void
{
    OpenBankingSecretsFixture::seed($user->id, $institutionId);
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
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->subDay()->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => CarbonImmutable::now()->subDays(5)->toDateTimeString(),
        'last_attempt_at' => CarbonImmutable::now()->subDay()->toDateTimeString(),
        'last_attempt_status' => 'consent_failed',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function orfCard(int $connectionId): Testable
{
    return Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $connectionId]);
}

beforeEach(function (): void {
    $this->orfSeededUserIds = [];
});

afterEach(function (): void {
    foreach ($this->orfSeededUserIds as $userId) {
        OpenBankingSecretsFixture::forget($userId);
    }
});

it('renders the expired-consent banner referencing the last successful sync, with a Reconnect CTA', function (): void {
    $user = orfUser('orf-banner');
    $this->orfSeededUserIds[] = $user->id;
    $this->actingAs($user);
    orfSeedSecrets($user);
    $connectionId = orfSeedConnection($user);

    orfCard($connectionId)
        ->assertSet('consentStatus', 'expired')
        ->assertSeeHtml('data-testid="open-banking-consent-expired-banner"')
        ->assertSeeHtml('role="alert"')
        ->assertSee('Consent expired — reconnect')
        ->assertSeeHtml('data-testid="ob-reconnect-button"');
});

it('does NOT render the expired-consent banner while consent is still valid', function (): void {
    $user = orfUser('orf-no-banner');
    $this->orfSeededUserIds[] = $user->id;
    $this->actingAs($user);
    orfSeedSecrets($user);
    $connectionId = orfSeedConnection($user, [
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'last_attempt_status' => 'ok',
    ]);

    orfCard($connectionId)
        ->assertSet('consentStatus', 'connected')
        ->assertDontSeeHtml('data-testid="open-banking-consent-expired-banner"');
});

it('reconnect() dispatches the wizard at Step 4 with the bank pre-selected, WITHOUT touching last_successful_sync_at', function (): void {
    $user = orfUser('orf-reconnect');
    $this->orfSeededUserIds[] = $user->id;
    $this->actingAs($user);
    orfSeedSecrets($user);
    $connectionId = orfSeedConnection($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $before = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('last_successful_sync_at');

    orfCard($connectionId)
        ->call('reconnect')
        ->assertDispatched('open-banking-wizard:open', startStep: 4, bankChoice: 'asn', otherInstitutionId: '');

    $after = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->value('last_successful_sync_at');
    expect($after)->toBe($before);
});

it('reconnect() pre-selects "other" with the raw institution id for a non-ASN/SNS connection', function (): void {
    $user = orfUser('orf-reconnect-other');
    $this->orfSeededUserIds[] = $user->id;
    $this->actingAs($user);
    orfSeedSecrets($user, 'SOMEBANKXXX');
    $connectionId = orfSeedConnection($user, ['institution_id' => 'SOMEBANKXXX']);

    orfCard($connectionId)
        ->call('reconnect')
        ->assertDispatched('open-banking-wizard:open', startStep: 4, bankChoice: 'other', otherInstitutionId: 'SOMEBANKXXX');
});

// Reconnecting the lapsed bank must name that bank. Reading the institution off
// a single stored session is what sent the reader to re-link whichever bank the
// file happened to hold last.
it('reconnect() names each card\'s OWN bank when two are connected', function (): void {
    $user = orfUser('orf-reconnect-two-banks');
    $this->orfSeededUserIds[] = $user->id;
    $this->actingAs($user);
    orfSeedSecrets($user);
    OpenBankingSecretsFixture::seed(
        $user->id,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        sessionId: 'fixture-session-second',
    );

    $first = orfSeedConnection($user, [
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'last_attempt_status' => 'ok',
    ]);
    $second = orfSeedConnection($user, [
        'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-2',
    ]);

    orfCard($first)
        ->call('reconnect')
        ->assertDispatched('open-banking-wizard:open', startStep: 4, bankChoice: 'asn', otherInstitutionId: '');

    orfCard($second)
        ->assertSet('consentStatus', 'expired')
        ->call('reconnect')
        ->assertDispatched('open-banking-wizard:open', startStep: 4, bankChoice: 'sns', otherInstitutionId: '');
});

it('stale data is never labeled fresh: the panel Last-successful-sync stays put across an expired-then-relinked cycle until an actual fetch succeeds', function (): void {
    $user = orfUser('orf-never-stale');
    $this->orfSeededUserIds[] = $user->id;
    $this->actingAs($user);
    orfSeedSecrets($user);
    $connectionId = orfSeedConnection($user);

    $component = orfCard($connectionId)->assertSet('consentStatus', 'expired');

    $originalLastSync = $component->get('lastSuccessfulSyncAtIso');

    // The re-link branch restores consent without touching
    // last_successful_sync_at; only a successful sync advances that column.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('open_banking_connections')
        ->where('id', $connectionId)
        ->update(['consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString()]);

    orfCard($connectionId)
        ->assertSet('consentStatus', 'connected')
        ->assertSet('lastSuccessfulSyncAtIso', $originalLastSync)
        ->assertDontSeeHtml('data-testid="open-banking-consent-expired-banner"');
});
