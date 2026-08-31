<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;

// The import run id is read once and held for the whole promotion, so a read
// that finds nothing used to file every promoted row of a life's ledger under
// import_run_id 0 — one silent coercion, applied to all of them.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    $this->user = User::create([
        'username' => 'promote-unreadable-run',
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

it('refuses a promotion whose import run it cannot read back', function (): void {
    // The run is gone by the time the promotion reads its id back, which is the
    // one outcome the four read-back sites disagreed about.
    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with(ltrim($query->sql), 'insert into "import_runs"')) {
            DB::table('import_runs')->delete();
        }
    });

    $promote = fn (): mixed => app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

    expect($promote)->toThrow(IdReadBackFailedException::class)
        ->and($this->conn->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
});
