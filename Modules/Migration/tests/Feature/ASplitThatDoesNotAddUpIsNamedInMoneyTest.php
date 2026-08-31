<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;

uses(RefreshDatabase::class);

// The split-mismatch reason lands in migration_staging_unmapped_items.reason,
// which preview and results both render verbatim to the reader. Printing the
// stored minor units there told somebody with a €123.45 transaction that "the
// legs add up to 12400 but the transaction is 12345" — on the same row as a
// label the same class already formats as money.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    $this->user = User::create([
        'username' => 'split-sum-mismatch',
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

    $this->stageAccount = function (string $currency): void {
        $this->conn->table('migration_staging_accounts')->insert([
            'user_id' => $this->user->id,
            'migration_run_id' => $this->runId,
            'source_external_id' => 'acct-1',
            'name' => 'Split Checking',
            'kind' => 'bank',
            'currency' => $currency,
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
    };

    $this->stageRow = function (string $id, int $amountMinor, string $currency, ?string $parentId, ?string $categoryExternalId): void {
        $this->conn->table('migration_staging_transactions')->insert([
            'user_id' => $this->user->id,
            'migration_run_id' => $this->runId,
            'source_external_id' => $id,
            'account_source_external_id' => 'acct-1',
            'posted_at' => '2026-03-04 00:00:00',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => $currency,
            'description' => 'Weekly shop',
            'cleared_status' => 'cleared',
            'is_split_parent' => $parentId === null,
            'parent_source_external_id' => $parentId,
            'category_source_external_id' => $categoryExternalId,
        ]);
    };

    $this->reason = function (): string {
        $row = $this->conn->table('migration_staging_unmapped_items')
            ->where('user_id', $this->user->id)
            ->where('migration_run_id', $this->runId)
            ->sole();

        return StoredCopy::read((string) $row->reason);
    };

    $this->promote = fn () => app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);
});

it('names both sides of a split mismatch as money rather than as the minor units it stores', function (): void {
    ($this->stageAccount)('EUR');
    ($this->stageRow)('tx-parent', -12345, 'EUR', null, null);
    ($this->stageRow)('tx-leg-a', -12000, 'EUR', 'tx-parent', 'cat-groceries');
    ($this->stageRow)('tx-leg-b', -400, 'EUR', 'tx-parent', 'cat-household');

    ($this->promote)();

    expect(($this->reason)())
        ->toContain('-€124.00')
        ->toContain('-€123.45')
        ->not->toContain('12400')
        ->not->toContain('12345');
});

// A yen has no minor unit, so the raw integer and the amount are the same
// digits and the defect above is invisible here. The currency has to come off
// the row for this to hold at all: EUR would render a hundredth of it.
it('names a zero-decimal mismatch in the currency the staged row carries', function (): void {
    ($this->stageAccount)('JPY');
    ($this->stageRow)('tx-parent', -1000, 'JPY', null, null);
    ($this->stageRow)('tx-leg-a', -600, 'JPY', 'tx-parent', 'cat-groceries');
    ($this->stageRow)('tx-leg-b', -500, 'JPY', 'tx-parent', 'cat-household');

    ($this->promote)();

    expect(($this->reason)())
        ->toContain('-¥1,100')
        ->toContain('-¥1,000');
});
