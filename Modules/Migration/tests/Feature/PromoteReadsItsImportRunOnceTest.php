<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;

uses(RefreshDatabase::class);

// Every promoted row asked the database which import_run to file itself
// under, and every answer was the same one. The lookup belongs to the
// promotion, not to the row.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    $this->user = User::create([
        'username' => 'promote-import-run-reader',
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

    $this->promoteStageTransactions = function (int $count): void {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'user_id' => $this->user->id,
                'migration_run_id' => $this->runId,
                'source_external_id' => 'tx-'.$i,
                'account_source_external_id' => 'acct-1',
                'posted_at' => '2026-03-01 00:00:00',
                'amount_minor' => -100 - $i,
                'currency' => 'EUR',
                'settled_amount_minor' => -100 - $i,
                'settled_currency' => 'EUR',
                'description' => 'Promoted row '.$i,
                'cleared_status' => 'cleared',
                'is_split_parent' => false,
            ];
        }
        $this->conn->table('migration_staging_transactions')->insert($batch);
    };
});

it('reads the migration import run once however many rows it promotes', function (): void {
    ($this->promoteStageTransactions)(600);

    $statements = 0;
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        if (stripos($query->sql, 'import_runs') !== false) {
            $statements++;
        }
    });

    app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

    // Look, insert, then read the new id back by the same match — three, and
    // three for six hundred rows as much as for one. The read-back is not
    // insertGetId() because lastInsertId() is per connection, and the badge
    // listener writes a `cache` row from inside that INSERT's own event.
    expect($statements)->toBe(3);
});

it('files every promoted row under one import run', function (): void {
    ($this->promoteStageTransactions)(12);

    $result = app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

    $runIds = $this->conn->table('transactions')
        ->where('user_id', $this->user->id)
        ->distinct()
        ->pluck('import_run_id')
        ->all();

    $sourceFormats = $this->conn->table('import_runs')
        ->where('user_id', $this->user->id)
        ->pluck('source_format')
        ->all();

    expect($result->transactionsInserted)->toBe(12)
        ->and($runIds)->toHaveCount(1)
        ->and($sourceFormats)->toBe(['migration_ynab4']);
});

it('reuses an import run a previous promotion already opened', function (): void {
    ($this->promoteStageTransactions)(3);
    app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

    $secondRunId = (int) $this->conn->table('migration_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'fixture-two.zip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->conn->table('migration_staging_accounts')->insert([
        'user_id' => $this->user->id,
        'migration_run_id' => $secondRunId,
        'source_external_id' => 'acct-2',
        'name' => 'Promote Savings',
        'kind' => 'bank',
        'currency' => 'EUR',
        'resolution_status' => 'unmapped',
    ]);
    $this->conn->table('migration_staging_transactions')->insert([
        'user_id' => $this->user->id,
        'migration_run_id' => $secondRunId,
        'source_external_id' => 'tx-second-1',
        'account_source_external_id' => 'acct-2',
        'posted_at' => '2026-04-01 00:00:00',
        'amount_minor' => -4242,
        'currency' => 'EUR',
        'settled_amount_minor' => -4242,
        'settled_currency' => 'EUR',
        'description' => 'Second promotion row',
        'cleared_status' => 'cleared',
        'is_split_parent' => false,
    ]);

    app(PromoteStagingToDomain::class)->promote($secondRunId, $this->user);

    expect($this->conn->table('import_runs')->where('user_id', $this->user->id)->count())->toBe(1);
});
