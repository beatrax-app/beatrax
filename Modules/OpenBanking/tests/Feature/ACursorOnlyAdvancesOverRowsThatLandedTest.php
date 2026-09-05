<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Tests\Support\AcoaStubRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

function acoaSeedCredentials(int $userId): void
{
    OpenBankingSecretsFixture::seed(
        $userId,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
    );
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
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    OpenBankingSecretsFixture::forget((int) $this->fixtureUser->id);
    $this->connectionId = acoaSeedConnection($this->fixtureUser);
    acoaSeedCredentials((int) $this->fixtureUser->id);

    $this->adapter = new AcoaStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $this->adapter);
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget((int) $this->fixtureUser->id);
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

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
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

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])->call('syncNow');

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
