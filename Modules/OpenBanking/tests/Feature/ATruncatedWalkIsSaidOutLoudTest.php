<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Enums\FetchStop;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Tests\Support\AtwsTruncatingRemoteSourceAdapter;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

function atwsSeedCredentials(int $userId): void
{
    OpenBankingSecretsFixture::seed(
        $userId,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
    );
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
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 06:30:00'));

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    OpenBankingSecretsFixture::forget((int) $this->fixtureUser->id);
    $this->connectionId = atwsSeedConnection($this->fixtureUser);
    atwsSeedCredentials((int) $this->fixtureUser->id);

    app()->instance(RemoteSourceAdapter::class, new AtwsTruncatingRemoteSourceAdapter(
        FetchWalk::stoppedAt(FetchStop::PageCap, 100, 25000),
    ));
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget((int) $this->fixtureUser->id);
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
    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
        ->call('syncNow')
        ->assertSet('syncFlashTone', 'error')
        ->assertSee('this run stopped early')
        ->assertDontSee('2 new transactions found.');
});

it('names the truncation on the transparency panel rather than calling it an error', function (): void {
    app()->call([new SyncOpenBankingAccountJob($this->connectionId), 'handle']);

    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])
        ->assertSeeHtml('data-testid="ob-last-attempt"')
        ->assertSee('stopped early')
        ->assertDontSee('failed (error)');
});
