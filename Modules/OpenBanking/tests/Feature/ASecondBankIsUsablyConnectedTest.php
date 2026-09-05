<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Transaction;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Enums\WizardStep;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;
use Modules\OpenBanking\Public\Http\Livewire\OpenBankingStatusRow;
use Modules\OpenBanking\Tests\Support\AsbPerAccountStubRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\AsbQueuedHttpClient;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// Linking a second bank used to rebind the installation's one live session to
// it, leaving the first row enabled and schedulable but unfetchable. Every act
// below is the reader's own: two consent dances through the real controllers,
// two enables through the settings screen, and two unattended syncs.

const ASB_BANK_A = 'ASNBNL21';

const ASB_BANK_B = 'SNSBNL21';

function asbSeedApplication(User $user): void
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);

    OpenBankingSecretsFixture::repository()->saveApplication($user->id, 'fixture-application-id', $privateKeyPem);
}

function asbAuthResponse(string $scaHost): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'url' => 'https://'.$scaHost.'/mock-consent-flow?token=xyz',
        'authorization_id' => 'auth-fixture',
    ], JSON_THROW_ON_ERROR));
}

function asbSessionResponse(string $sessionId, string $accountUid): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'session_id' => $sessionId,
        'accounts' => [['uid' => $accountUid]],
    ], JSON_THROW_ON_ERROR));
}

// One handler for the whole dance, because Laravel caches a controller
// instance on its Route: a second request to the same route reuses the first
// request's collaborators, so swapping the client between requests would swap
// nothing.
function asbQueueClient(MockHandler $mock): void
{
    app()->instance(
        EnableBankingHttpClient::class,
        new AsbQueuedHttpClient(app(EnableBankingJwtSigner::class), $mock),
    );
}

// The whole dance: connect resolves and stores the bank's SCA host, the
// callback exchanges the code and stores that bank's session.
function asbLinkBank(User $user, string $institutionId, string $scaHost): int
{
    test()->get('/oauth/connect/open-banking?institution_id='.$institutionId)
        ->assertRedirect('https://'.$scaHost.'/mock-consent-flow?token=xyz');

    /** @var OpenBankingStateRepository $states */
    $states = app(OpenBankingStateRepository::class);
    $state = $states->issueState($user->id, $institutionId);

    test()->get('/oauth/callback/open-banking?state='.$state.'&code=fake-code-'.$institutionId)
        ->assertRedirect(route('settings.open-banking'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('open_banking_connections')
        ->where('user_id', $user->id)
        ->where('institution_id', $institutionId)
        ->value('id');
}

// The reader's own enable: the callback flashes the new connection id and the
// acknowledgement is still fresh, which is exactly what mount() acts on.
function asbEnableThroughTheScreen(int $connectionId): void
{
    session([
        'open_banking_connected' => $connectionId,
        'open_banking_acknowledged' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(OpenBankingSettingsPage::class);
}

function asbRow(int $connectionId): stdClass
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var stdClass $row */
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    return $row;
}

// Deliberately unlike each other in date and amount as well as in reference:
// the import fingerprint collapses look-alike rows, and two banks agreeing to
// the cent on the same day is what it exists to catch.
function asbRemoteRow(string $sourceRef, int $amountMinor, string $bookedOn): SourceTransactionDto
{
    return new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse($bookedOn),
        postedAt: CarbonImmutable::parse($bookedOn),
        valueDate: CarbonImmutable::parse($bookedOn),
        ownIban: 'NL57ASNB0123456789',
        counterpartyIban: 'NL00RABO0123456789',
        counterpartyName: 'Fixture Merchant '.$sourceRef,
        currency: 'EUR',
        amountMinor: $amountMinor,
        sourceRef: $sourceRef,
        description: 'Fixture EB row '.$sourceRef,
        rawPayload: [],
        sourceRowIndex: 0,
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    OpenBankingSecretsFixture::forget($this->fixtureUser->id);
    asbSeedApplication($this->fixtureUser);

    asbQueueClient(new MockHandler([
        asbAuthResponse('sca.asnbank.example'),
        asbSessionResponse('session-a', 'acc-uid-a'),
        asbAuthResponse('sca.snsbank.example'),
        asbSessionResponse('session-b', 'acc-uid-b'),
    ]));

    $this->connectionA = asbLinkBank($this->fixtureUser, ASB_BANK_A, 'sca.asnbank.example');
    $this->connectionB = asbLinkBank($this->fixtureUser, ASB_BANK_B, 'sca.snsbank.example');
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget($this->fixtureUser->id);
    CarbonImmutable::setTestNow();
});

it('keeps each bank its own session, host and consent after the second is linked', function (): void {
    $secrets = OpenBankingSecretsFixture::repository();

    expect($secrets->connectedInstitutions($this->fixtureUser->id))
        ->toEqualCanonicalizing([ASB_BANK_A, ASB_BANK_B]);

    $a = $secrets->loadOrThrow($this->fixtureUser->id, ASB_BANK_A);
    $b = $secrets->loadOrThrow($this->fixtureUser->id, ASB_BANK_B);

    expect($a->sessionId)->toBe('session-a')
        ->and($a->bankScaHost)->toBe('sca.asnbank.example')
        ->and($b->sessionId)->toBe('session-b')
        ->and($b->bankScaHost)->toBe('sca.snsbank.example')
        // One application registration, two consents hanging off it.
        ->and($b->applicationId)->toBe($a->applicationId)
        ->and($b->privateKeyPem)->toBe($a->privateKeyPem);
});

it('leaves the first bank enabled and schedulable when the second is enabled', function (): void {
    asbEnableThroughTheScreen($this->connectionA);
    asbEnableThroughTheScreen($this->connectionB);

    expect((bool) asbRow($this->connectionA)->enabled)->toBeTrue()
        ->and(asbRow($this->connectionA)->consent_expires_at)->not->toBeNull()
        ->and((bool) asbRow($this->connectionB)->enabled)->toBeTrue()
        ->and(asbRow($this->connectionB)->consent_expires_at)->not->toBeNull();

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSet('enabled', true)
        ->assertSet('connectionIds', [$this->connectionA, $this->connectionB])
        ->assertSee('ASN Bank')
        ->assertSee('SNS (de Volksbank)');
});

it('fetches from both banks in one unattended round, each with its own session', function (): void {
    asbEnableThroughTheScreen($this->connectionA);
    asbEnableThroughTheScreen($this->connectionB);

    $adapter = new AsbPerAccountStubRemoteSourceAdapter([
        'acc-uid-a' => [asbRemoteRow('asb-from-bank-a', -1099, '2026-07-18')],
        'acc-uid-b' => [asbRemoteRow('asb-from-bank-b', -2570, '2026-07-17')],
    ]);
    app()->instance(RemoteSourceAdapter::class, $adapter);
    app()->forgetInstance(OpenBankingFetchService::class);
    app()->forgetInstance(OpenBankingSyncRunner::class);

    app()->call([new SyncOpenBankingAccountJob($this->connectionA), 'handle']);
    app()->call([new SyncOpenBankingAccountJob($this->connectionB), 'handle']);

    expect($adapter->seen)->toBe([
        ['accountUid' => 'acc-uid-a', 'institutionId' => ASB_BANK_A, 'sessionId' => 'session-a'],
        ['accountUid' => 'acc-uid-b', 'institutionId' => ASB_BANK_B, 'sessionId' => 'session-b'],
    ]);

    // Both rounds reached the ledger, so "connected" is a claim about money
    // arriving rather than about two rows existing.
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->pluck('source_ref')->all())
        ->toEqualCanonicalizing(['asb-from-bank-a', 'asb-from-bank-b']);

    expect(asbRow($this->connectionA)->last_successful_sync_at)->not->toBeNull()
        ->and(asbRow($this->connectionA)->fetched_through_at)->not->toBeNull()
        ->and(asbRow($this->connectionB)->last_successful_sync_at)->not->toBeNull()
        ->and(asbRow($this->connectionB)->fetched_through_at)->not->toBeNull();
});

// Disconnecting is still all-or-nothing, so a bank the reader believes is off
// cannot keep its consent or its place in tomorrow's schedule.
it('disconnect clears every bank at once', function (): void {
    asbEnableThroughTheScreen($this->connectionA);
    asbEnableThroughTheScreen($this->connectionB);

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('startDisconnect')
        ->call('disconnect')
        ->assertSet('enabled', false)
        ->assertSet('connectionIds', []);

    expect((bool) asbRow($this->connectionA)->enabled)->toBeFalse()
        ->and((bool) asbRow($this->connectionB)->enabled)->toBeFalse()
        ->and(OpenBankingSecretsFixture::repository()->connectedInstitutions($this->fixtureUser->id))->toBe([]);
});

// The one freshness line on the mobile screen has to answer for both banks at
// once, and the honest answer is the older of the two: a reader asking how
// current their data is gets the weakest link, not the flattering one.
it('names both banks on the status row and quotes the older sync', function (): void {
    asbEnableThroughTheScreen($this->connectionA);
    asbEnableThroughTheScreen($this->connectionB);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('open_banking_connections')->where('id', $this->connectionA)
        ->update(['last_successful_sync_at' => CarbonImmutable::now()->subDays(9)->toDateTimeString()]);
    $db->connection()->table('open_banking_connections')->where('id', $this->connectionB)
        ->update(['last_successful_sync_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString()]);

    Livewire::test(OpenBankingStatusRow::class)
        ->assertSet('expired', false)
        ->assertSee('ASN Bank, SNS (de Volksbank)')
        ->assertSee('1 week ago')
        ->assertDontSee('5 minutes ago');
});

// Adding a bank is not a first connection: the reader has already registered an
// application and already answered the third-party warning.
it('opens the wizard at the bank picker rather than at the warning', function (): void {
    asbEnableThroughTheScreen($this->connectionA);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeHtml('data-testid="ob-connect-another-button"')
        ->call('connectAnotherBank')
        ->assertDispatched('open-banking-wizard:open', startStep: WizardStep::Bank->value);
});
