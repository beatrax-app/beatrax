<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\RecordResult;

// Both phones threw an unhandled QueryException on the very first entry and
// saved on the retry. insertGetId() ends in lastInsertId(), which is per
// CONNECTION: the sidebar's badge listener writes a `cache` row from inside
// the import_runs INSERT's own QueryExecuted, and its rowid came back instead.

// The phones put the cache in the database, on the connection every other
// statement uses (mobile-app/bootstrap/app.php). The suite runs it in an array
// and cannot see the interleave at all, so the store goes back where the
// device keeps it — and the singletons that already hold one are dropped.
function cashBookWithTheCacheTheDeviceHas(): void
{
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(CacheRepository::class);
    app()->forgetInstance(NavCountsService::class);
    app('cache')->forgetDriver(['array', 'database']);
}

// No account and no import run: a brand-new install has neither, and both are
// created by the first entry, which is the only entry that can hit this.
function cashBookCleanInstallUser(): User
{
    return User::query()->create([
        'username' => 'clean-install-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'JPY',
    ]);
}

function cashBookFirstEntry(User $user): Testable
{
    return Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '1500')
        ->set('date', '2026-08-29')
        ->set('counterparty', 'Konbini')
        ->call('add');
}

it('saves the first cash entry a clean install makes', function (): void {
    cashBookWithTheCacheTheDeviceHas();
    $user = cashBookCleanInstallUser();

    // The badges are read when the page loads, which is what puts the first
    // row in `cache` and moves the generation key's rowid off the id the
    // import run is about to be given.
    app(NavCountsService::class)->forUser($user->id);

    cashBookFirstEntry($user)->assertSet('error', '');

    $connection = app(DatabaseManager::class)->connection();
    $runId = $connection->table('import_runs')->where('user_id', $user->id)->value('id');
    $accountId = $connection->table('accounts')->where('user_id', $user->id)->value('id');
    $entry = $connection->table('transactions')->where('user_id', $user->id)->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->import_run_id)->toBe((int) $runId)
        ->and((int) $entry->account_id)->toBe((int) $accountId);
});

// The nav-count generation key is seeded once and only ever incremented after,
// so the interleave is the FIRST write to a counted table on an install and
// nothing later. A second entry passing proves nothing about the first.
it('gives the import run the id the import_runs row actually has', function (): void {
    cashBookWithTheCacheTheDeviceHas();
    $user = cashBookCleanInstallUser();
    app(NavCountsService::class)->forUser($user->id);

    cashBookFirstEntry($user)->assertSet('error', '');

    $connection = app(DatabaseManager::class)->connection();

    expect($connection->table('cache')->count())->toBeGreaterThan(1)
        ->and($connection->table('import_runs')->where('user_id', $user->id)->count())->toBe(1)
        ->and($connection->table('transactions')->where('user_id', $user->id)->count())->toBe(1);
});

// iOS drew the statement, its bindings and the database's absolute path over
// the app as a native panel. A reader can act on a sentence and on nothing in
// that panel, and the bindings there are the entry they had just typed.
it('answers a write that failed with a sentence, not the statement', function (): void {
    $user = cashBookCleanInstallUser();

    app()->bind(RecordsTransactions::class, static fn (): RecordsTransactions => new class implements RecordsTransactions
    {
        public function __invoke(iterable $canonical, User $user, bool $captureForSync = true): RecordResult
        {
            throw new QueryException(
                'sqlite',
                'insert or ignore into "transactions" ("counterparty_name") values (?)',
                ['Konbini'],
                new RuntimeException('SQLSTATE[23000]: FOREIGN KEY constraint failed'),
            );
        }
    });

    $error = cashBookFirstEntry($user)->get('error');

    expect($error)->toBe('That entry was not recorded. Try adding it again.')
        ->and($error)->not->toContain('insert')
        ->and($error)->not->toContain('transactions')
        ->and($error)->not->toContain('SQLSTATE');
});
