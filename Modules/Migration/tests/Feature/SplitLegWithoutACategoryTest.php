<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;

uses(RefreshDatabase::class);

// transaction_splits.category_id is NOT NULL, so a source split leg that
// carries no category cannot be stored. Dropping the leg on its own left the
// survivors short of the parent, and the sum check then threw — after the
// parent row was already inserted. The reader saw a 500 and a transaction
// whose split had quietly vanished.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    $this->user = User::create([
        'username' => 'split-leg-no-category',
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
        'name' => 'Split Checking',
        'kind' => 'bank',
        'currency' => 'EUR',
        'resolution_status' => 'unmapped',
    ]);

    foreach (['cat-groceries' => 'Groceries', 'cat-household' => 'Household'] as $externalId => $name) {
        $this->conn->table('migration_staging_categories')->insert([
            'user_id' => $this->user->id,
            'migration_run_id' => $this->runId,
            'source_external_id' => $externalId,
            'source_group_name' => 'Everyday',
            'name' => $name,
            'parent_source_external_id' => null,
            'kind' => 'expense',
            'resolution_status' => 'unmapped',
            'resolved_category_id' => null,
        ]);
    }

    $this->stageParent = function (int $amountMinor): void {
        $this->conn->table('migration_staging_transactions')->insert([
            'user_id' => $this->user->id,
            'migration_run_id' => $this->runId,
            'source_external_id' => 'tx-parent',
            'account_source_external_id' => 'acct-1',
            'posted_at' => '2026-03-04 00:00:00',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'description' => 'Weekly shop',
            'cleared_status' => 'cleared',
            'is_split_parent' => true,
            'parent_source_external_id' => null,
            'category_source_external_id' => null,
        ]);
    };

    $this->stageLeg = function (string $id, int $amountMinor, ?string $categoryExternalId): void {
        $this->conn->table('migration_staging_transactions')->insert([
            'user_id' => $this->user->id,
            'migration_run_id' => $this->runId,
            'source_external_id' => $id,
            'account_source_external_id' => 'acct-1',
            'posted_at' => '2026-03-04 00:00:00',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'description' => 'Leg '.$id,
            'cleared_status' => 'cleared',
            'is_split_parent' => false,
            'parent_source_external_id' => 'tx-parent',
            'category_source_external_id' => $categoryExternalId,
        ]);
    };

    $this->promote = fn () => app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

    $this->parentTransaction = fn () => $this->conn->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('source_row_index', 0)
        ->orderBy('id')
        ->first();
});

it('promotes a split whose legs all carry a category', function (): void {
    ($this->stageParent)(-3000);
    ($this->stageLeg)('tx-leg-a', -1000, 'cat-groceries');
    ($this->stageLeg)('tx-leg-b', -2000, 'cat-household');

    $result = ($this->promote)();

    expect($result->splitsCreated)->toBe(1);
    expect($this->conn->table('transaction_splits')->where('user_id', $this->user->id)->count())->toBe(2);
    expect($this->conn->table('migration_staging_unmapped_items')->where('migration_run_id', $this->runId)->count())->toBe(0);
});

it('does not throw when one leg of a split carries no category', function (): void {
    ($this->stageParent)(-3000);
    ($this->stageLeg)('tx-leg-a', -1000, 'cat-groceries');
    ($this->stageLeg)('tx-leg-b', -1000, 'cat-household');
    ($this->stageLeg)('tx-leg-c', -1000, null);

    ($this->promote)();
})->throwsNoExceptions();

it('imports the parent at its full amount when one leg carries no category, rather than at the sum of the legs it could keep', function (): void {
    ($this->stageParent)(-3000);
    ($this->stageLeg)('tx-leg-a', -1000, 'cat-groceries');
    ($this->stageLeg)('tx-leg-b', -1000, 'cat-household');
    ($this->stageLeg)('tx-leg-c', -1000, null);

    $result = ($this->promote)();

    expect(($this->parentTransaction)()->amount_minor)->toBe(-3000);
    expect($result->splitsCreated)->toBe(0);
    expect($this->conn->table('transaction_splits')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('tells the reader the split was not carried instead of dropping it in silence', function (): void {
    ($this->stageParent)(-3000);
    ($this->stageLeg)('tx-leg-a', -1000, 'cat-groceries');
    ($this->stageLeg)('tx-leg-b', -1000, 'cat-household');
    ($this->stageLeg)('tx-leg-c', -1000, null);

    ($this->promote)();

    $reported = $this->conn->table('migration_staging_unmapped_items')
        ->where('user_id', $this->user->id)
        ->where('migration_run_id', $this->runId)
        ->get();

    expect($reported)->toHaveCount(1);
    expect($reported[0]->item_type)->toBe('extra');
    expect($reported[0]->source_external_id)->toBe('tx-parent');
    expect(StoredCopy::read((string) $reported[0]->display_label))->toBe('Transaction: Weekly shop · 4 Mar 2026 · -€30.00');
    expect(StoredCopy::read((string) $reported[0]->reason))->toContain('1 split leg of 3 carries no category');
});

it('imports the parent whole and reports it when the legs do not add up to the transaction', function (): void {
    ($this->stageParent)(-3000);
    ($this->stageLeg)('tx-leg-a', -1000, 'cat-groceries');
    ($this->stageLeg)('tx-leg-b', -1500, 'cat-household');

    $result = ($this->promote)();

    expect(($this->parentTransaction)()->amount_minor)->toBe(-3000);
    expect($result->splitsCreated)->toBe(0);
    expect($this->conn->table('transaction_splits')->where('user_id', $this->user->id)->count())->toBe(0);

    $reported = $this->conn->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $this->runId)
        ->get();

    expect($reported)->toHaveCount(1);
    expect($reported[0]->reason)->toContain('add up to -€25.00');
});
