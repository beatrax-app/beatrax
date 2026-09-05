<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;
use Modules\OpenBanking\Internal\Support\ConsentWindow;
use Modules\OpenBanking\Public\Http\Livewire\OpenBankingStatusRow;
use Modules\OpenBanking\Tests\Support\ArcsRefusingRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

function arcsSeedCredentials(int $userId): void
{
    OpenBankingSecretsFixture::seed(
        $userId,
        consentExpiresAt: CarbonImmutable::parse('2026-12-19 00:00:00'),
    );
}

function arcsSeedConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-arcs-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        // Months of window left: the calendar is not what ended this consent.
        'consent_expires_at' => CarbonImmutable::parse('2026-12-19 00:00:00')->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => CarbonImmutable::parse('2026-07-18 06:00:00')->toDateTimeString(),
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    OpenBankingSecretsFixture::forget($this->fixtureUser->id);
    $this->connectionId = arcsSeedConnection($this->fixtureUser);
    arcsSeedCredentials($this->fixtureUser->id);

    app()->instance(RemoteSourceAdapter::class, new ArcsRefusingRemoteSourceAdapter);

    $this->row = function (): stdClass {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);

        /** @var stdClass $row */
        $row = $db->connection()->table('open_banking_connections')->where('id', $this->connectionId)->first();

        return $row;
    };
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget($this->fixtureUser->id);
    CarbonImmutable::setTestNow();
});

it('writes the revocation onto the connection when the scheduled sync discovers it', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    $row = ($this->row)();

    expect($row->consent_revoked_at)->not->toBeNull();
    expect($row->last_attempt_status)->toBe('consent_failed');
});

it('stops reading Connected on the settings page once the bank has withdrawn the session', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
        ->assertSet('consentStatus', ConsentStatus::Revoked->value)
        ->assertSee('Ended by your bank — reconnect')
        ->assertSee('Your bank ended the connection')
        // The attempt line must not blame a cause the pill has already ruled
        // out: this consent's window has months left on it.
        ->assertSee('failed (ended by your bank)')
        ->assertDontSee('failed (consent expired)')
        ->assertSeeHtml('data-testid="ob-reconnect-button"');
});

it('names the withdrawal rather than an expiry on the mobile status row', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', true)
        ->assertSee('Your bank ended the connection — reconnect needed.')
        ->assertDontSee('Consent expired');
});

// Re-fetching against a session the aggregator has already refused only earns
// another 401, so the boundary the fetch service and the job re-check has to
// treat a revocation as the end of the window it is.
it('refuses to fetch and drops out of the daily scheduler while the revocation stands', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $scheduled = ConsentWindow::constrainToLive(
        $db->connection()->table('open_banking_connections')->where('enabled', true),
        CarbonImmutable::now(),
    )->pluck('id')->all();

    expect($scheduled)->toBe([]);

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
        ->call('syncNow')
        ->assertSet('syncFlashMessage', '');
});

it('returns to Connected when the reader re-links and a sync succeeds', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('open_banking_connections')
        ->where('id', $this->connectionId)
        ->update(['consent_revoked_at' => null]);

    // The fetch service is a singleton and already holds the refusing adapter.
    app()->instance(RemoteSourceAdapter::class, new ArcsRefusingRemoteSourceAdapter(refuse: false));
    app()->forgetInstance(OpenBankingFetchService::class);
    app()->forgetInstance(OpenBankingSyncRunner::class);

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
        ->assertSet('consentStatus', ConsentStatus::Connected->value)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'zero');
});
