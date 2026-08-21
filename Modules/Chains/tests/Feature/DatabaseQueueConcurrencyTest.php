<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

uses(RefreshDatabase::class)->group('Phase14');

/**
 * @return array{user: User, account: Account, run: ImportRun}
 */
function seedConcurrencyScenario(): array
{
    $user = User::query()->create([
        'username' => 'concurrency-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $icsAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS concurrency fixture',
        'slug' => 'ics-concurrency',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/concurrency.pdf',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // scenario-1 empirical contract: 23 ICS statement rows -> 84732 cents.
    $totalMinor = 84732;
    $count = 23;
    $each = intdiv($totalMinor, $count);
    $remainder = $totalMinor - ($each * ($count - 1));
    for ($i = 1; $i <= $count; $i++) {
        $absRow = $i === $count ? $remainder : $each;
        $day = str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT);
        Transaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $icsAccount->id,
            'type' => 'expense',
            'posted_at' => "2026-05-{$day}",
            'booked_at' => "2026-05-{$day} 12:00:00",
            'value_date' => "2026-05-{$day}",
            'amount_minor' => -$absRow,
            'currency' => 'EUR',
            'settled_amount_minor' => -$absRow,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Merchant '.$i,
            'counterparty_normalized' => 'merchant-'.$i,
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('cx'.$i, 64, 'c', STR_PAD_LEFT),
            'fingerprint_version' => 3,
        ]);
    }
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $icsAccount->id,
        'type' => 'transfer_in',
        'posted_at' => '2026-05-29',
        'booked_at' => '2026-05-29 12:00:00',
        'value_date' => '2026-05-29',
        'amount_minor' => $totalMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $totalMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'ASN Bulk',
        'counterparty_normalized' => 'asn-bulk',
        'normalization_version' => 1,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => 9999,
        'fingerprint' => str_pad('ctr', 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $icsAccount->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -$totalMinor,
        'open_balance_minor' => $totalMinor,
        'state' => 'open',
    ]);

    return ['user' => $user, 'account' => $icsAccount, 'run' => $run];
}

beforeEach(function (): void {
    // Exercise the shipped slice: the SQLite-backed database lock store and
    // the database queue connection, not the array/sync phpunit.xml defaults
    // which never touch cache_locks or jobs.
    config([
        'cache.locks_store' => 'database',
        'queue.default' => 'database',
    ]);
});

it('rejects a duplicate concurrent unique-lock acquire on the database lock store', function (): void {
    $fixtures = seedConcurrencyScenario();
    $job = new ResolveChainLinksJob($fixtures['user']->id);

    // Derive the unique-lock key through the same framework path the
    // queue uses — never a hard-coded 'laravel_unique_job:...' literal.
    $lockKey = UniqueLock::getKey($job);
    $store = Cache::store(config('cache.locks_store'));

    $firstLock = $store->lock($lockKey, $job->uniqueFor());
    expect($firstLock->get())->toBeTrue();

    $secondLock = $store->lock($lockKey, $job->uniqueFor());
    expect($secondLock->get())->toBeFalse();

    $firstLock->forceRelease();
});

it('writes exactly one chain_resolution_runs row when handle runs once', function (): void {
    $fixtures = seedConcurrencyScenario();
    $job = new ResolveChainLinksJob($fixtures['user']->id);

    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $this->app->make(RetypeByAliasResolver::class),
        $this->app->make(PairsTransferLegs::class),
        $this->app->make(UpsertsCardStatements::class),
        $this->app->make(IcsSettlementResolver::class),
        $this->app->make(PaypalFundingResolver::class),
    );

    expect(
        ChainResolutionRun::query()->where('user_id', $fixtures['user']->id)->count(),
    )->toBe(1);
});

it('database queue admits one job row and a duplicate dispatch is dropped by the held unique lock', function (): void {
    $fixtures = seedConcurrencyScenario();
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $job = new ResolveChainLinksJob($fixtures['user']->id);
    $lockKey = UniqueLock::getKey($job);

    // Dispatch happens strictly post-commit. ResolveChainLinksJob is
    // ShouldBeUniqueUntilProcessing, so the first dispatch also takes the
    // per-user unique lock on the database lock store.
    ResolveChainLinksJob::dispatch($fixtures['user']->id);

    expect($db->connection()->table('jobs')->count())->toBe(1);

    $contendedLock = Cache::store(config('cache.locks_store'))
        ->lock($lockKey, $job->uniqueFor());
    expect($contendedLock->get())->toBeFalse();

    ResolveChainLinksJob::dispatch($fixtures['user']->id);

    expect($db->connection()->table('jobs')->count())->toBe(1);
});
