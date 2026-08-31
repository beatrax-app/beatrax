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
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

// A bank answers for the window it was asked about and nothing else. That is
// what makes an over-advanced cursor permanent: a row behind dateFrom is not in
// the answer, this time or ever again.
final class AcoaStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    /** @var list<array{date: string, minor: int, ref: string}> */
    public array $available = [];

    /** @var list<array{from: string, to: string}> */
    public array $windows = [];

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->windows[] = ['from' => $window->dateFrom->toDateString(), 'to' => $window->dateTo->toDateString()];

        $index = 0;
        foreach ($this->available as $row) {
            $bookedOn = CarbonImmutable::parse($row['date']);
            if ($bookedOn->lessThan($window->dateFrom) || $bookedOn->greaterThan($window->dateTo)) {
                continue;
            }

            yield new SourceTransactionDto(
                bookedAt: $bookedOn,
                postedAt: $bookedOn,
                valueDate: $bookedOn,
                ownIban: 'NL57ASNB0123456789',
                counterpartyIban: 'NL91ABNA0417164300',
                counterpartyName: 'Fixture Merchant',
                currency: 'EUR',
                amountMinor: $row['minor'],
                sourceRef: $row['ref'],
                description: 'EB row '.$row['ref'],
                rawPayload: [],
                sourceRowIndex: $index,
            );
            $index++;
        }

        return FetchWalk::exhausted(1, count($this->available));
    }
}

function acoaSeedCredentials(): void
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

function acoaSeedConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-acoa-1',
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

function acoaConnectionRow(int $connectionId): stdClass
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
    $this->connectionId = acoaSeedConnection($this->fixtureUser);
    acoaSeedCredentials();

    $this->adapter = new AcoaStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $this->adapter);
});

afterEach(function (): void {
    if (is_file($this->secretsPath)) {
        @unlink($this->secretsPath);
    }
    CarbonImmutable::setTestNow();
});

// "Sync now" stages a preview the reader confirms themselves; it writes no
// ledger row. A cursor that moves on it declares three transactions read, and
// the two the reader had not confirmed yet are behind it for good.
it('leaves every previewed row still fetchable, so all three reach the ledger on the next confirmed sync', function (): void {
    $this->adapter->available = [
        ['date' => '2026-07-17', 'minor' => -1101, 'ref' => 'acoa-1'],
        ['date' => '2026-07-18', 'minor' => -1202, 'ref' => 'acoa-2'],
        ['date' => '2026-07-19', 'minor' => -1303, 'ref' => 'acoa-3'],
    ];

    Livewire::test(OpenBankingSettingsPage::class)
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'success')
        ->assertSet('syncFlashMessage', '3 new transactions found.');

    expect(Transaction::query()->count())->toBe(0);

    $afterPreview = acoaConnectionRow($this->connectionId);
    expect($afterPreview->fetched_through_at)->toBeNull();
    expect($afterPreview->last_attempt_status)->toBe('ok');

    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect(Transaction::query()->count())->toBe(3);
    expect(Transaction::query()->pluck('source_ref')->sort()->values()->all())
        ->toBe(['acoa-1', 'acoa-2', 'acoa-3']);

    expect(acoaConnectionRow($this->connectionId)->fetched_through_at)->not->toBeNull();
});

// The freshness signal a reader reads and the cursor a fetch resumes from are
// two different questions, and the preview answers only the first.
it('advances the freshness signal on a preview while leaving the cursor alone', function (): void {
    $this->adapter->available = [['date' => '2026-07-18', 'minor' => -999, 'ref' => 'acoa-fresh']];

    Livewire::test(OpenBankingSettingsPage::class)->call('syncNow');

    $row = acoaConnectionRow($this->connectionId);
    expect($row->last_successful_sync_at)->not->toBeNull();
    expect($row->fetched_through_at)->toBeNull();
});

it('opens the first window on the 90-day initial lookback', function (): void {
    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect($this->adapter->windows[0])->toBe(['from' => '2026-04-20', 'to' => '2026-07-19']);
});

// Weekend settlement, a delayed merchant capture, a reversal: the bank posts a
// transaction days behind the dates it has already answered for. A window that
// starts where the last one ended cannot see it, and no later window ever will.
it('re-reads far enough back to pick up a transaction the bank posted five days behind the cursor', function (): void {
    $this->adapter->available = [['date' => '2026-07-18', 'minor' => -1500, 'ref' => 'acoa-onstream']];

    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);
    expect(Transaction::query()->count())->toBe(1);

    // The bank books a transaction whose date is behind the window it already
    // answered, then the next day's sync runs.
    $this->adapter->available[] = ['date' => '2026-07-14', 'minor' => -2750, 'ref' => 'acoa-backdated'];
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 06:30:00'));

    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    expect(Transaction::query()->pluck('source_ref')->sort()->values()->all())
        ->toBe(['acoa-backdated', 'acoa-onstream']);
    expect($this->adapter->windows[1])->toBe(['from' => '2026-07-05', 'to' => '2026-07-20']);
});

// The overlap costs re-fetching rows that already landed. They have to dedupe
// rather than double, or the cure is worse than the disease.
it('re-fetches the overlap without landing a second copy of anything in it', function (): void {
    $this->adapter->available = [['date' => '2026-07-18', 'minor' => -1500, 'ref' => 'acoa-dupe']];

    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 06:30:00'));
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    expect(Transaction::query()->where('source_ref', 'acoa-dupe')->count())->toBe(1);
});
