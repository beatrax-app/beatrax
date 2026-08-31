<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// The `edge` fixture is one export carrying the four register shapes that each
// broke this importer: an R/Reconciled cleared cell, two splits back to back at
// one payee and date, and a split whose legs cancel.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-edge-shapes-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);

    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationFixturePaths::ynab4Dir('edge'),
        'Beatrax Edge Budget.zip',
    );
    $this->result = app(ConfirmMigration::class)->__invoke($run->id, $this->user);
    $this->runId = $run->id;
});

// A YNAB register row has no id of its own, so a row is named here the way the
// reader would name it — by the day it fell on and what it cost.
function edgeSourceExternalId(string $postedDate, int $amountMinor): string
{
    /** @var object $row */
    $row = test()->db->connection()->table('migration_staging_transactions')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', test()->runId)
        ->whereNull('parent_source_external_id')
        ->whereDate('posted_at', $postedDate)
        ->where('amount_minor', $amountMinor)
        ->firstOrFail(['source_external_id']);

    return (string) $row->source_external_id;
}

function edgeTransaction(string $postedDate, int $amountMinor): stdClass
{
    /** @var stdClass $row */
    $row = test()->db->connection()->table('transactions')
        ->where('user_id', test()->user->id)
        ->where('source_ref', 'migration:ynab4:'.edgeSourceExternalId($postedDate, $amountMinor))
        ->first(['id', 'amount_minor', 'status']);

    return $row;
}

it('a split whose legs cancel imports as a zero-net transaction instead of aborting every other row in the file', function (): void {
    // Ten register rows fold into seven transactions; before the fix the
    // zero-net pair threw and none of the seven were written at all.
    $imported = $this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count();
    expect($imported)->toBe(7);

    $zeroNet = edgeTransaction('2026-02-07', 0);
    expect((int) $zeroNet->amount_minor)->toBe(0);

    // Its legs cannot be stored (a Beatrax split leg must share the parent's
    // sign), so the transaction lands on its own and the loss is surfaced.
    $legs = $this->db->connection()->table('transaction_splits')->where('transaction_id', $zeroNet->id)->count();
    expect($legs)->toBe(0);

    /** @var object $surfaced */
    $surfaced = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $this->runId)
        ->where('item_type', UnmappedItemType::Extra->value)
        ->where('source_external_id', edgeSourceExternalId('2026-02-07', 0))
        ->sole(['display_label']);

    // Neither YNAB export carries a description, so naming the row by that one
    // column left every lost transaction in this list reading the same.
    expect(StoredCopy::read((string) $surfaced->display_label))->toBe('Transaction: Reclass · 7 Feb 2026 · €0.00');
});

it('two splits at the same payee and date stay two transactions, keyed on the memo\'s own "n of m"', function (): void {
    $first = edgeTransaction('2026-02-06', -3000);
    $second = edgeTransaction('2026-02-06', -1200);

    expect((int) $first->amount_minor)->toBe(-3000)
        ->and((int) $second->amount_minor)->toBe(-1200);

    // Four legs across two parents — never one parent of -42.00 with four.
    expect($this->db->connection()->table('transaction_splits')->where('transaction_id', $first->id)->count())->toBe(2)
        ->and($this->db->connection()->table('transaction_splits')->where('transaction_id', $second->id)->count())->toBe(2);
});

it('every spelling the Cleared column ships imports as the status it names', function (): void {
    expect(edgeTransaction('2026-02-02', -4500)->status)->toBe(ClearedStatus::Reconciled->value)
        ->and(edgeTransaction('2026-02-03', -1000)->status)->toBe(ClearedStatus::Reconciled->value)
        ->and(edgeTransaction('2026-02-04', -500)->status)->toBe(ClearedStatus::Cleared->value)
        ->and(edgeTransaction('2026-02-05', -300)->status)->toBe(ClearedStatus::Uncleared->value);
});

it('a budget cell that says zero is staged; one that says nothing is not staged at all', function (): void {
    $staged = $this->db->connection()->table('migration_staging_budget_assignments')
        ->where('migration_run_id', $this->runId)
        ->pluck('budgeted_minor', 'source_category_external_id');

    expect($staged->has('cat:frequent/groceries'))->toBeTrue()
        ->and((int) $staged->get('cat:frequent/groceries'))->toBe(0)
        ->and($staged->has('cat:frequent/household'))->toBeFalse()
        ->and((int) $staged->get('cat:frequent/utilities'))->toBe(15000);
});
