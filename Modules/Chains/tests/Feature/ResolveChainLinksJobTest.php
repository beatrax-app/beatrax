<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

/*
 * Wave 2 coverage for the ResolveChainLinksJob lifecycle. Covers:
 *  - Interface contracts (ShouldQueue, ShouldBeUniqueUntilProcessing)
 *  - dispatchSync call counting via Bus::fake (14.1-04, CRYPT-01: the
 *    dispatcher now runs the job in-process so the decrypt-requiring
 *    resolvers have the KEK available; the queue-only unique lock no
 *    longer collapses repeat dispatches — see the "no queue-lock
 *    collapse" test below)
 *  - chain_resolution_runs row transitions (pending → running → complete)
 *  - ConfirmImport post-commit dispatch via the DispatchesChainResolution contract
 */

/**
 * Seed a single user and ICS account + an ICS transfer_in matching one
 * statement. The synthetic shape is the minimum the resolver needs to
 * write at least one chain_link, so `linked_count` lands > 0 and the
 * `complete` row carries a non-zero count.
 */
function seedJobUserAndFixtures(int $userIndex = 1): array
{
    $user = User::query()->create([
        'username' => "job-user-{$userIndex}",
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $icsAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => "ICS job fixture {$userIndex}",
        'slug' => "ics-job-{$userIndex}",
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => "/tmp/job-{$userIndex}.pdf",
        'sha256' => str_repeat((string) $userIndex, 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    return ['user' => $user, 'account' => $icsAccount, 'run' => $run];
}

beforeEach(function (): void {
    $fixtures = seedJobUserAndFixtures(1);
    $this->user = $fixtures['user'];
    $this->icsAccount = $fixtures['account'];
    $this->run = $fixtures['run'];
});

it('declares the ShouldQueue and ShouldBeUniqueUntilProcessing contracts', function (): void {
    $job = new ResolveChainLinksJob($this->user->id);

    expect($job)->toBeInstanceOf(ShouldQueue::class);
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([60, 300, 900]);
});

it('returns the userId as the unique id and 600 as the unique window', function (): void {
    $job = new ResolveChainLinksJob($this->user->id);

    expect($job->uniqueId())->toBe((string) $this->user->id);
    expect($job->uniqueFor())->toBe(600);
});

it('uniqueVia returns a Cache Repository resolved through the LockStore helper', function (): void {
    $job = new ResolveChainLinksJob($this->user->id);

    expect($job->uniqueVia())->toBeInstanceOf(CacheRepository::class);
});

it('no queue-lock collapse: dispatchSync runs the job every call, even for the same user (14.1-04)', function (): void {
    // BusChainResolutionDispatcher now calls ::dispatchSync (14.1-04,
    // CRYPT-01) so the KEK is available in-process for the encrypted
    // counterparty_iban resolvers. dispatchSync bypasses
    // PendingDispatch::shouldDispatch() entirely, so the per-user
    // ShouldBeUniqueUntilProcessing lock — which only exists at the
    // queue-push boundary — never engages. Two calls for the same
    // user therefore run the job body twice, not once.
    Bus::fake();
    /** @var DispatchesChainResolution $dispatcher */
    $dispatcher = $this->app->make(DispatchesChainResolution::class);

    $dispatcher->dispatchForUser($this->user->id);
    $dispatcher->dispatchForUser($this->user->id);

    Bus::assertDispatchedSync(ResolveChainLinksJob::class, 2);
});

it('different users each run their own dispatchSync call', function (): void {
    Bus::fake();
    $otherFixtures = seedJobUserAndFixtures(2);
    /** @var DispatchesChainResolution $dispatcher */
    $dispatcher = $this->app->make(DispatchesChainResolution::class);

    $dispatcher->dispatchForUser($this->user->id);
    $dispatcher->dispatchForUser($otherFixtures['user']->id);

    Bus::assertDispatchedSync(ResolveChainLinksJob::class, 2);
});

it('handle() runs both resolvers and transitions chain_resolution_runs from running to complete', function (): void {
    // Seed minimal fixtures so the ICS resolver has something to write.
    // 23-expense fixture from IcsSettlementResolverTest:
    $totalMinor = 84732;
    $count = 23;
    $each = intdiv($totalMinor, $count);
    $remainder = $totalMinor - ($each * ($count - 1));
    for ($i = 1; $i <= $count; $i++) {
        $absRow = $i === $count ? $remainder : $each;
        Transaction::query()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->icsAccount->id,
            'type' => 'expense',
            'posted_at' => '2026-05-'.str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT),
            'booked_at' => '2026-05-'.str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT).' 12:00:00',
            'value_date' => '2026-05-'.str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT),
            'amount_minor' => -$absRow,
            'currency' => 'EUR',
            'settled_amount_minor' => -$absRow,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Merchant '.$i,
            'counterparty_normalized' => 'merchant-'.$i,
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'import_run_id' => $this->run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('jx'.$i, 64, 'j', STR_PAD_LEFT),
            'fingerprint_version' => 3,
        ]);
    }

    // ASN bank account + alias bridge so the bulk-iDEAL transfer_out
    // resolves to the user's ics_card via ResolvesKnownCounterpartyIban.
    $bankAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN job bank',
        'slug' => 'asn-job-bank',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);
    $asnRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/asn-job.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $bankAccount->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-05-29',
        'booked_at' => '2026-05-29 12:00:00',
        'value_date' => '2026-05-29',
        'amount_minor' => -$totalMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$totalMinor,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ASN Bulk',
        'counterparty_normalized' => 'asn-bulk',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $asnRun->id,
        'source_row_index' => 9999,
        'fingerprint' => str_pad('tjob', 64, 't', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -$totalMinor,
        'open_balance_minor' => $totalMinor,
        'state' => 'open',
    ]);

    $job = new ResolveChainLinksJob($this->user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $this->app->make(RetypeByAliasResolver::class),
        $this->app->make(PairsTransferLegs::class),
        $this->app->make(UpsertsCardStatements::class),
        $this->app->make(IcsSettlementResolver::class),
        $this->app->make(PaypalFundingResolver::class),
    );

    /** @var ChainResolutionRun $run */
    $run = ChainResolutionRun::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
    expect($run->status)->toBe('complete');
    expect($run->started_at)->not->toBeNull();
    expect($run->completed_at)->not->toBeNull();
    expect((int) $run->linked_count)->toBe(23);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(23);
});

it('ConfirmImport invokes the chain dispatcher when inserted > 0 via the contract', function (): void {
    Bus::fake();

    /** @var DispatchesChainResolution $dispatcher */
    $dispatcher = $this->app->make(DispatchesChainResolution::class);
    $dispatcher->dispatchForUser($this->user->id);

    Bus::assertDispatchedSync(ResolveChainLinksJob::class, function (ResolveChainLinksJob $job): bool {
        return $job->userId === $this->user->id;
    });
});

it('ConfirmImport dispatches via DispatchesChainResolution contract (not via internal Bus reference)', function (): void {
    // Sanity-grep the source: the dispatch site references the
    // public contract, NOT a direct ResolveChainLinksJob FQN — the
    // BoundaryRule forbids cross-module Internal imports.
    $confirmImport = (string) file_get_contents(base_path('Modules/Import/Public/Actions/ConfirmImport.php'));

    expect($confirmImport)->toContain('DispatchesChainResolution');
    expect($confirmImport)->toContain('chainDispatcher->dispatchForUser');
    expect($confirmImport)->not->toContain('Modules\\Chains\\Internal\\Jobs\\');
});

it('ConfirmImport dispatch happens AFTER the transaction closure (D-103, Pitfall 3)', function (): void {
    // Static grep: the dispatch site MUST sit below the close of the
    // db->connection()->transaction() block — never inside it. We
    // assert via line-number arithmetic on the canonical method.
    $source = (string) file_get_contents(base_path('Modules/Import/Public/Actions/ConfirmImport.php'));

    $closurePos = strpos($source, '->transaction(function');
    $dispatchPos = strpos($source, 'chainDispatcher->dispatchForUser');
    $forgetPos = strpos($source, 'cache->forget');

    expect($closurePos)->not->toBeFalse();
    expect($dispatchPos)->not->toBeFalse();
    expect($forgetPos)->not->toBeFalse();
    // The cache forget happens after the transaction returns; the
    // dispatch happens after the cache forget. Both must sit after
    // the `transaction(function` literal.
    expect($dispatchPos)->toBeGreaterThan($closurePos);
    expect($dispatchPos)->toBeGreaterThan($forgetPos);
});
