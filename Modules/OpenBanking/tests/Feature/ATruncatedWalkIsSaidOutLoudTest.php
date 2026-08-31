<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Transaction;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Enums\FetchStop;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

// A bounded walk that says nothing is a silent truncation: the reader sees a
// green tile, a fresh timestamp and part of a window, with no way to tell which
// part is missing.
final class AtwsTruncatingRemoteSourceAdapter implements RemoteSourceAdapter
{
    public function __construct(private readonly FetchWalk $walk) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        foreach ([['2026-07-17', -1401, 'atws-1'], ['2026-07-18', -1502, 'atws-2']] as $index => [$date, $minor, $ref]) {
            yield new SourceTransactionDto(
                bookedAt: CarbonImmutable::parse($date),
                postedAt: CarbonImmutable::parse($date),
                valueDate: CarbonImmutable::parse($date),
                ownIban: 'NL57ASNB0123456789',
                counterpartyIban: 'NL91ABNA0417164300',
                counterpartyName: 'Fixture Merchant',
                currency: 'EUR',
                amountMinor: $minor,
                sourceRef: $ref,
                description: 'EB row '.$ref,
                rawPayload: [],
                sourceRowIndex: $index,
            );
        }

        return $this->walk;
    }
}

function atwsSeedCredentials(): void
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);

    $repo = new OpenBankingSecretsRepository(new Filesystem, app(SecretShield::class), app(Encrypter::class));
    $repo->save(new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: 'fixture-session-id',
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
        bankScaHost: 'sca.asnbank.example',
        institutionId: 'ASNBNL21',
    ));
}

function atwsSeedConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-atws-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'last_successful_sync_at' => null,
        'fetched_through_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function atwsConnectionRow(int $connectionId): stdClass
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var stdClass $row */
    $row = $db->connection()->table('open_banking_connections')->where('id', $connectionId)->first();

    return $row;
}

beforeEach(function (): void {
    $this->secretsPath = storage_path('app/secrets/open-banking.json');
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->connectionId = atwsSeedConnection($this->fixtureUser);
    atwsSeedCredentials();

    app()->instance(RemoteSourceAdapter::class, new AtwsTruncatingRemoteSourceAdapter(
        FetchWalk::stoppedAt(FetchStop::PageCap, 100, 25000),
    ));
});

afterEach(function (): void {
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow();
});

it('records a walk that hit its bound as its own status rather than as a success', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    $row = atwsConnectionRow($this->connectionId);

    expect($row->last_attempt_status)->toBe(SyncAttemptStatus::Truncated->value);
    expect($row->last_attempt_at)->not->toBeNull();
});

// The rows that did arrive are real and are kept. What must not move is the
// claim about how much of the window has been read.
it('keeps the rows it did fetch while leaving both the cursor and the freshness signal alone', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    expect(Transaction::query()->count())->toBe(2);

    $row = atwsConnectionRow($this->connectionId);
    expect($row->fetched_through_at)->toBeNull();
    expect($row->last_successful_sync_at)->toBeNull();
});

it('tells the reader the run stopped early instead of counting the rows it did get', function (): void {
    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSee('this run stopped early')
        ->assertDontSee('2 new transactions found.');
});

it('names the truncation on the transparency panel rather than calling it an error', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeHtml('data-testid="ob-last-attempt"')
        ->assertSee('stopped early')
        ->assertDontSee('failed (error)');
});
