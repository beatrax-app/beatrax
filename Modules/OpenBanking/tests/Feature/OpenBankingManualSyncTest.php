<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LockStore;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Tests\Support\OmsStubRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// Sync now belongs to one connection, so every case here drives the card that
// bank is drawn by rather than the page the cards are listed on.

function omsSeedCredentials(int $userId, string $institutionId = OpenBankingSecretsFixture::INSTITUTION_ID): void
{
    OpenBankingSecretsFixture::seed(
        $userId,
        $institutionId,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
    );
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
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'consent_revoked_at' => null,
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function omsCard(int $connectionId): Testable
{
    return Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $connectionId]);
}

function omsRow(int $connectionId): stdClass
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var stdClass $row */
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    return $row;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $seeded = $this->seedFixtureUserAndAccount();
    /** @var User $user */
    $user = $seeded['user'];
    $this->omsUser = $user;
    $this->actingAs($user);
    OpenBankingSecretsFixture::forget($user->id);
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget($this->omsUser->id);
    CarbonImmutable::setTestNow();
});

it('an eligible connection: syncNow surfaces "N new transactions found." with a working Review import link, and advances last_successful_sync_at', function (): void {
    omsSeedCredentials($this->omsUser->id);
    $connectionId = omsSeedConnection($this->omsUser);

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

    omsCard($connectionId)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'success')
        ->assertSet('syncFlashMessage', '1 new transaction found.')
        ->assertSeeHtml('data-testid="ob-review-import-link"');

    $row = omsRow($connectionId);

    expect($row->last_successful_sync_at)->not->toBeNull();
    expect($row->last_attempt_status)->toBe('ok');
});

it('an eligible connection with zero new rows: "No new transactions." and last_successful_sync_at still advances', function (): void {
    omsSeedCredentials($this->omsUser->id);
    $connectionId = omsSeedConnection($this->omsUser);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([]));

    omsCard($connectionId)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'zero')
        ->assertSet('syncFlashMessage', 'No new transactions.');

    expect(omsRow($connectionId)->last_successful_sync_at)->not->toBeNull();
});

// One bank pressing Sync now must not fetch for the other: the card carries its
// own connection id, and the runner is handed that id rather than "the"
// connection the reader happens to hold.
it('syncs only the bank whose card was pressed', function (): void {
    omsSeedCredentials($this->omsUser->id);
    OpenBankingSecretsFixture::seed(
        $this->omsUser->id,
        OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
        sessionId: 'fixture-session-second',
    );

    $first = omsSeedConnection($this->omsUser);
    $second = omsSeedConnection($this->omsUser, [
        'institution_id' => OpenBankingSecretsFixture::SECOND_INSTITUTION_ID,
        'account_uid' => 'acc-uid-fixture-2',
        'bank_display_name' => 'SNS (de Volksbank)',
    ]);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([]));

    omsCard($second)->call('syncNow')->assertSet('syncFlashTone', 'zero');

    expect(omsRow($second)->last_successful_sync_at)->not->toBeNull();
    expect(omsRow($first)->last_successful_sync_at)->toBeNull();
    expect(omsRow($first)->last_attempt_at)->toBeNull();
});

it('a disabled connection: syncNow is a no-op — no fetch, no timestamp write, no flash', function (): void {
    omsSeedCredentials($this->omsUser->id);
    $connectionId = omsSeedConnection($this->omsUser, ['enabled' => false]);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([]));

    omsCard($connectionId)
        ->assertDontSeeHtml('data-testid="open-banking-sync-now"')
        ->call('syncNow')
        ->assertSet('syncFlashMessage', '')
        ->assertSet('syncFlashTone', '');

    $row = omsRow($connectionId);

    expect($row->last_attempt_at)->toBeNull();
    expect($row->last_successful_sync_at)->toBeNull();
});

it('an expired-consent connection: the button is disabled with "Reconnect first", and syncNow is a no-op', function (): void {
    omsSeedCredentials($this->omsUser->id);
    $connectionId = omsSeedConnection($this->omsUser, [
        'consent_expires_at' => CarbonImmutable::parse('2026-01-01 00:00:00')->toDateTimeString(),
    ]);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([]));

    omsCard($connectionId)
        ->assertSeeHtml('data-testid="ob-sync-now-disabled-caption"')
        ->assertSee('Reconnect first')
        ->call('syncNow')
        ->assertSet('syncFlashMessage', '')
        ->assertSet('syncFlashTone', '');

    expect(omsRow($connectionId)->last_attempt_at)->toBeNull();
});

it('a generic fetch failure: rose flash, last_attempt_status=error, and last_successful_sync_at stays UNCHANGED', function (): void {
    omsSeedCredentials($this->omsUser->id);
    $priorSuccess = CarbonImmutable::parse('2026-07-18 06:00:00')->toDateTimeString();
    $connectionId = omsSeedConnection($this->omsUser, ['last_successful_sync_at' => $priorSuccess]);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter(
        throws: EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 500, 'server error')
    ));

    omsCard($connectionId)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSet('syncFlashMessage', 'Enable Banking is temporarily unavailable. Try again shortly.');

    $row = omsRow($connectionId);

    expect($row->last_successful_sync_at)->toBe($priorSuccess);
    expect($row->last_attempt_status)->toBe('error');
});

it('a consent failure (HTTP 401): rose flash with the specific reason, marks consent_failed, and dispatches OpenBankingConsentFailed', function (): void {
    Event::fake([OpenBankingConsentFailed::class]);

    omsSeedCredentials($this->omsUser->id);
    $connectionId = omsSeedConnection($this->omsUser);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter(
        throws: EnableBankingApiException::errorStatus('GET https://api.enablebanking.com/...', 401, 'unauthorized')
    ));

    omsCard($connectionId)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSet('syncFlashMessage', 'Consent expired — reconnect.');

    $row = omsRow($connectionId);

    expect($row->last_successful_sync_at)->toBeNull();
    expect($row->last_attempt_status)->toBe('consent_failed');

    $userId = $this->omsUser->id;
    Event::assertDispatched(
        OpenBankingConsentFailed::class,
        fn (OpenBankingConsentFailed $event): bool => $event->connectionId === $connectionId && $event->userId === $userId
    );
});

// The scheduled job and this button write the same two timestamps for the same
// connection. Without a shared lock, whichever UPDATE lands second decides
// last_successful_sync_at, and one of the two attempts is unaccounted for.
it('declines a manual sync while the connection\'s scheduled sync already holds its lock', function (): void {
    omsSeedCredentials($this->omsUser->id);
    $connectionId = omsSeedConnection($this->omsUser);

    app()->instance(RemoteSourceAdapter::class, new OmsStubRemoteSourceAdapter([]));

    $held = LockStore::forUniqueJobs()->lock(
        UniqueLock::getKey(new SyncOpenBankingAccountJob($connectionId)),
        SyncOpenBankingAccountJob::UNIQUE_FOR_SECONDS,
    );
    expect($held->get())->toBeTrue();

    try {
        omsCard($connectionId)
            ->call('syncNow')
            ->assertSet('syncFlashTone', 'busy')
            ->assertSet('syncFlashMessage', 'A sync is already running. Try again in a moment.');
    } finally {
        $held->release();
    }

    $row = omsRow($connectionId);

    expect($row->last_attempt_at)->toBeNull()
        ->and($row->last_successful_sync_at)->toBeNull();
});
