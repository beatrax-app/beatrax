<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;
use Modules\OpenBanking\Tests\Support\SncrStubRemoteSourceAdapter;

uses(RefreshDatabase::class);

// Nobody is watching a 06:00 sync, so a run it could not file has to survive as
// unfinished work. Confirmed-with-nothing-written plus the idempotency key on
// the window is how a whole day of transactions left no trace anywhere. The
// attempt is NothingImported rather than Error: nothing errored, the account
// the rows landed in is unnamed.

const SNCR_UNNAMED_IBAN = 'NL22RABO0987654321';

const SNCR_BOOKED_ON = '2026-07-18';

function sncrSeedConnection(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::parse('2026-07-19 06:00:00')->toDateTimeString();

    return (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $user->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'acc-uid-sncr-1',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::parse('2026-10-19 00:00:00')->toDateTimeString(),
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function sncrSeedCredentials(int $userId): void
{
    OpenBankingSecretsFixture::seed(
        $userId,
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
    );
}

function sncrConnectionRow(int $connectionId): stdClass
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
    OpenBankingSecretsFixture::forget((int) $this->fixtureUser->id);
    $this->connectionId = sncrSeedConnection($this->fixtureUser);
    sncrSeedCredentials((int) $this->fixtureUser->id);

    $this->adapter = new SncrStubRemoteSourceAdapter;
    app()->instance(RemoteSourceAdapter::class, $this->adapter);
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget((int) $this->fixtureUser->id);
    CarbonImmutable::setTestNow();
});

it('leaves the run unconfirmed and the cursor where it was when every fetched row names an account the ledger does not have', function (): void {
    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect($this->adapter->fetches)->toBe(1);
    expect(Transaction::query()->count())->toBe(0);

    /** @var ImportRun $run */
    $run = ImportRun::query()->where('user_id', $this->fixtureUser->id)->latest('id')->firstOrFail();
    expect($run->status)->not->toBe(ImportRunStatus::Confirmed->value);
    expect($run->confirmed_at)->toBeNull();

    $connection = sncrConnectionRow($this->connectionId);
    expect($connection->last_successful_sync_at)->toBeNull();
    expect($connection->last_attempt_status)->toBe(SyncAttemptStatus::NothingImported->value);
});

// The point of not advancing the cursor: the window stays open, so the rows
// arrive the moment the reader names the account they belong to. Advance it and
// the next fetch starts the day after, and this transaction is never offered
// again by anyone.
it('lands the same window once the account exists', function (): void {
    $job = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$job, 'handle']);

    expect(Transaction::query()->count())->toBe(0);

    Account::query()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Rabo current',
        'slug' => 'rabo-current',
        'kind' => 'bank',
        'iban' => SNCR_UNNAMED_IBAN,
        'default_currency' => 'EUR',
    ]);

    $retry = new SyncOpenBankingAccountJob($this->connectionId);
    app()->call([$retry, 'handle']);

    expect($this->adapter->fetches)->toBe(2);
    expect(Transaction::query()->count())->toBe(1);

    $connection = sncrConnectionRow($this->connectionId);
    expect($connection->last_successful_sync_at)->not->toBeNull();
    expect($connection->last_attempt_status)->toBe(SyncAttemptStatus::Ok->value);
});
