<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;

// `import_runs` is one of the tables a write to invalidates the sidebar badges,
// and the badge listener writes a `cache` row from inside that INSERT's own
// event. insertGetId() reads lastInsertId(), which is per connection, so the
// whole promotion filed itself under a run id belonging to `cache`.

uses(RefreshDatabase::class);

// The phones put the cache in the database, on the connection every other
// statement uses (mobile-app/bootstrap/app.php). The suite runs it in an array
// and cannot see the interleave at all, so the store goes back where the
// device keeps it — and the singletons that already hold one are dropped.
beforeEach(function (): void {
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(CacheRepository::class);
    app()->forgetInstance(NavCountsService::class);
    app('cache')->forgetDriver(['array', 'database']);

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    $this->user = User::create([
        'username' => 'promote-clean-install',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->runId = (int) $this->conn->table('migration_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'fixture.zip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->conn->table('migration_staging_accounts')->insert([
        'user_id' => $this->user->id,
        'migration_run_id' => $this->runId,
        'source_external_id' => 'acct-1',
        'name' => 'Promote Checking',
        'kind' => 'bank',
        'currency' => 'EUR',
        'resolution_status' => 'unmapped',
    ]);

    $this->conn->table('migration_staging_transactions')->insert([
        'user_id' => $this->user->id,
        'migration_run_id' => $this->runId,
        'source_external_id' => 'tx-1',
        'account_source_external_id' => 'acct-1',
        'posted_at' => '2026-03-01 00:00:00',
        'amount_minor' => -1234,
        'currency' => 'EUR',
        'settled_amount_minor' => -1234,
        'settled_currency' => 'EUR',
        'description' => 'Promoted row',
        'cleared_status' => 'cleared',
        'is_split_parent' => false,
    ]);
});

it('files a promotion under the import run row it just opened', function (): void {
    // The badges are read when the page loads, which is what puts the first
    // row in `cache` and moves the generation key's rowid off the id the
    // import run is about to be given.
    app(NavCountsService::class)->forUser($this->user->id);

    $result = app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

    $runId = $this->conn->table('import_runs')->where('user_id', $this->user->id)->value('id');
    $filedUnder = $this->conn->table('transactions')
        ->where('user_id', $this->user->id)
        ->distinct()
        ->pluck('import_run_id')
        ->all();

    expect($this->conn->table('cache')->count())->toBeGreaterThan(1)
        ->and($result->transactionsInserted)->toBe(1)
        ->and($filedUnder)->toBe([(int) $runId]);
});
