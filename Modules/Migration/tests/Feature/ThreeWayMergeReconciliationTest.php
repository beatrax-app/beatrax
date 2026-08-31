<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Models\Category;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Http\Livewire\PreviewMigration;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-3wm-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('ThreeWayMerge: a category changed on BOTH source and local is a conflict (untouched + listed); a category changed only on source applies cleanly', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $household = Category::query()->where('user_id', $this->user->id)->where('name', 'Household')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');

    // Local edit between the two imports: Groceries only, Household left alone.
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    // v2 changes BOTH Groceries (200.00 -> 250.00, colliding with the local
    // edit) AND Household (100.00 -> 120.00, untouched locally).
    $reconciliationRun = app(CheckForUpdates::class)->__invoke($firstRun->id, $this->user, 'ynab4', MigrationFixturePaths::ynab4Dir('v2'));

    // The preview writes nothing, so Household still reads the v1 value here.
    $householdBeforeConfirm = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $household->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $householdBeforeConfirm)->toBe(10000);

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    // Conflict: the local 300.00 survives, the source's 250.00 is not applied.
    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $groceriesAssigned)->toBe(30000);

    // Household: clean APPLY — no local edit, so the source's 120.00 lands.
    $householdAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $household->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $householdAssigned)->toBe(12000);
});

it('ThreeWayMerge: the conflict is listed for the user, not silently swallowed', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    expect($reconciliationRun->status)->toBe('needs_attention');

    $unmappedConflicts = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->get();
    expect($unmappedConflicts)->not->toBeEmpty();
});

it('ThreeWayMerge: confirming a needs_attention reconciliation run does NOT overwrite the kept-local budget-assignment conflict', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');

    // Local edit that collides with v2's Groceries change (200.00 -> 250.00).
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );
    expect($reconciliationRun->status)->toBe('needs_attention');

    // Confirming a needs_attention run re-enters promote(); its second,
    // unconditional pass over budget assignments is what used to overwrite
    // the kept-local value.
    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');

    expect((int) $groceriesAssigned)->toBe(30000);

    $confirmedRun = MigrationRun::query()->findOrFail($reconciliationRun->id);
    expect($confirmedRun->status)->toBe('confirmed');
});

// v2's Register.csv changes exactly two rows' amounts and nothing else. A YNAB
// register row carries no id of its own, so a row is named here the way the
// reader would name it — by the day it fell on and what it cost then.
function reconciledSourceExternalId(int $runId, string $postedDate, int $amountMinor): string
{
    /** @var object $row */
    $row = test()->db->connection()->table('migration_staging_transactions')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', $runId)
        ->whereNull('parent_source_external_id')
        ->whereDate('posted_at', $postedDate)
        ->where('amount_minor', $amountMinor)
        ->firstOrFail(['source_external_id']);

    return (string) $row->source_external_id;
}

function reconciledTransactionId(int $runId, string $postedDate, int $amountMinor): int
{
    return (int) test()->db->connection()->table('migration_source_map')
        ->where('user_id', test()->user->id)
        ->where('source_product', 'ynab4')
        ->where('source_entity_type', 'transaction')
        ->where('source_external_id', reconciledSourceExternalId($runId, $postedDate, $amountMinor))
        ->value('beatrax_id');
}

it('ThreeWayMerge: a transaction amount changed only on source applies cleanly; an untouched transaction stays untouched', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceryTxId = reconciledTransactionId($firstRun->id, '2026-01-15', -4500);
    expect($groceryTxId)->toBeGreaterThan(0);

    $salaryTxId = reconciledTransactionId($firstRun->id, '2026-01-16', 200000);
    expect($salaryTxId)->toBeGreaterThan(0);

    // No local edit to either transaction.
    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    // The preview is a read: nothing has moved yet.
    expect((int) $this->db->connection()->table('transactions')->where('id', $groceryTxId)->value('amount_minor'))->toBe(-4500);

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $grocery = $this->db->connection()->table('transactions')->where('id', $groceryTxId)->first();
    expect((int) $grocery->amount_minor)->toBe(-5000);

    // The settled leg is the one every balance, budget and report sums, so a
    // correction that stops at the native leg has reached nothing.
    expect((int) $grocery->settled_amount_minor)->toBe(-5000);
    expect((string) $grocery->settled_currency)->toBe((string) $grocery->currency);
    expect($grocery->fx_rate_used)->toBeNull();

    // Untouched entity: neither applied nor flagged.
    $salary = $this->db->connection()->table('transactions')->where('id', $salaryTxId)->first();
    expect((int) $salary->amount_minor)->toBe(200000);
    expect((int) $salary->settled_amount_minor)->toBe(200000);

    $amountConflicts = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->get()
        ->filter(static fn (object $row): bool => StoredCopy::names(
            is_string($row->display_label) ? $row->display_label : null,
            'migration::unmapped.conflict.transaction_amount',
        ));
    expect($amountConflicts)->toBeEmpty();
});

it('ThreeWayMerge: a transaction amount changed on BOTH source and local is a conflict (untouched + listed)', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $txId = reconciledTransactionId($firstRun->id, '2026-01-19', -1500);
    expect($txId)->toBeGreaterThan(0);

    // Local edit -1500 -> -1600, colliding with v2's -1800.
    $this->db->connection()->table('transactions')->where('id', $txId)->update(['amount_minor' => -1600]);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    expect($reconciliationRun->status)->toBe('needs_attention');

    $amount = (int) $this->db->connection()->table('transactions')->where('id', $txId)->value('amount_minor');
    expect($amount)->toBe(-1600);

    $amountConflicts = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->get()
        ->filter(static fn (object $row): bool => StoredCopy::names(
            is_string($row->display_label) ? $row->display_label : null,
            'migration::unmapped.conflict.transaction_amount',
        ));
    expect($amountConflicts)->not->toBeEmpty();
});

it('a kept-local transaction-amount conflict survives BOTH CheckForUpdates and a subsequent Confirm', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $txId = reconciledTransactionId($firstRun->id, '2026-01-19', -1500);

    $this->db->connection()->table('transactions')->where('id', $txId)->update(['amount_minor' => -1600]);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );
    expect($reconciliationRun->status)->toBe('needs_attention');

    $amountAfterCheckForUpdates = (int) $this->db->connection()->table('transactions')->where('id', $txId)->value('amount_minor');
    expect($amountAfterCheckForUpdates)->toBe(-1600);

    // Unlike a budget assignment, a transaction with a migration_source_map
    // row is never revisited by promote() at all, so no skip-list is needed
    // to protect the kept-local amount here.
    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $amountAfterConfirm = (int) $this->db->connection()->table('transactions')->where('id', $txId)->value('amount_minor');
    expect($amountAfterConfirm)->toBe(-1600);

    $confirmedRun = MigrationRun::query()->findOrFail($reconciliationRun->id);
    expect($confirmedRun->status)->toBe('confirmed');
});

it('ThreeWayMerge: a genuine fingerprint-uniqueness collision on transaction-amount apply is still handled gracefully (left unchanged, recorded, no exception)', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceryTxId = reconciledTransactionId($firstRun->id, '2026-01-15', -4500);
    expect($groceryTxId)->toBeGreaterThan(0);

    /** @var stdClass $groceryRow */
    $groceryRow = $this->db->connection()->table('transactions')->where('id', $groceryTxId)->first();
    $originalAmount = (int) $groceryRow->amount_minor;

    // A clone occupying every column of `transactions_fingerprint_uq` at v2's
    // target amount, so the reconciliation UPDATE hits a real composite-unique
    // collision. Its own fingerprint is fabricated, so this INSERT collides with nothing.
    $cloneId = $this->db->connection()->table('transactions')->insertGetId([
        'user_id' => $groceryRow->user_id,
        'account_id' => $groceryRow->account_id,
        'type' => $groceryRow->type,
        'posted_at' => $groceryRow->posted_at,
        'booked_at' => $groceryRow->booked_at,
        'value_date' => $groceryRow->value_date,
        'amount_minor' => -5000, // the exact value v2's reconciliation will try to apply
        'currency' => $groceryRow->currency,
        'settled_amount_minor' => -5000,
        'settled_currency' => $groceryRow->settled_currency,
        'fx_rate_used' => $groceryRow->fx_rate_used,
        'counterparty_name' => $groceryRow->counterparty_name,
        'counterparty_iban' => $groceryRow->counterparty_iban,
        'counterparty_normalized' => $groceryRow->counterparty_normalized,
        'normalization_version' => $groceryRow->normalization_version,
        'description' => $groceryRow->description,
        'category_id' => $groceryRow->category_id,
        'source_format' => $groceryRow->source_format,
        'import_run_id' => $groceryRow->import_run_id,
        'source_row_index' => $groceryRow->source_row_index,
        'source_ref' => $groceryRow->source_ref,
        'fingerprint' => str_repeat('a', 64), // fabricated, distinct from any real computed value
        'fingerprint_version' => $groceryRow->fingerprint_version,
        'status' => $groceryRow->status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($cloneId)->toBeGreaterThan(0);

    // Must not throw: a genuine fingerprint collision is a handled outcome.
    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );
    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    // The source's -5000 collided with the clone, so nothing was applied.
    $amountAfter = (int) $this->db->connection()->table('transactions')->where('id', $groceryTxId)->value('amount_minor');
    expect($amountAfter)->toBe($originalAmount);

    // The collision is recorded for the user, not silently swallowed.
    $collisionRows = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'extra')
        ->get()
        ->filter(static fn (object $row): bool => StoredCopy::names(
            is_string($row->display_label) ? $row->display_label : null,
            'migration::unmapped.label.amount_update',
        ));
    expect($collisionRows)->not->toBeEmpty();
});

// The resolution is deferred: `CheckForUpdates` records a conflict with
// `resolution` NULL, and `ConfirmMigration` is the only place one is applied.
// Committing the decision at CheckForUpdates time left the toggle cosmetic.

it('ThreeWayMerge: a take_source resolution on a transaction-amount conflict moves BOTH legs at Confirm', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $txId = reconciledTransactionId($firstRun->id, '2026-01-19', -1500);

    // Local edit -1500 -> -1600, colliding with v2's -1800.
    $this->db->connection()->table('transactions')->where('id', $txId)
        ->update(['amount_minor' => -1600, 'settled_amount_minor' => -1600]);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );
    expect($reconciliationRun->status)->toBe('needs_attention');

    $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->where('entity_type', 'transaction')
        ->where('field_name', 'amount_minor')
        ->update(['resolution' => 'take_source']);

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $row = $this->db->connection()->table('transactions')->where('id', $txId)->first();
    expect((int) $row->amount_minor)->toBe(-1800);
    expect((int) $row->settled_amount_minor)->toBe(-1800);
    expect($row->fx_rate_used)->toBeNull();
});

it('ThreeWayMerge: a take_source resolution on a budget-assignment conflict applies the SOURCE value at Confirm', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');

    // Local edit that collides with v2's Groceries change (200.00 -> 250.00).
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );
    expect($reconciliationRun->status)->toBe('needs_attention');

    // CheckForUpdates leaves the conflict unresolved — 300.00 is untouched.
    $groceriesAssignedBeforeConfirm = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $groceriesAssignedBeforeConfirm)->toBe(30000);

    $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->where('entity_type', 'budget_assignment')
        ->update(['resolution' => 'take_source']);

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');

    expect((int) $groceriesAssigned)->toBe(25000);

    $confirmedRun = MigrationRun::query()->findOrFail($reconciliationRun->id);
    expect($confirmedRun->status)->toBe('confirmed');
});

it('ThreeWayMerge: keep_local (default, no toggle interaction) leaves the Beatrax value unchanged at Confirm', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    // No toggle interaction: the resolution column stays NULL.
    $resolution = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->where('entity_type', 'budget_assignment')
        ->value('resolution');
    expect($resolution)->toBeNull();

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');

    // A NULL resolution behaves exactly like keep_local.
    expect((int) $groceriesAssigned)->toBe(30000);
});

it('ThreeWayMerge: switching the toggle keep_local -> take_source -> keep_local persists the LAST choice, and Confirm honors it', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    $conflictId = (int) $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->where('entity_type', 'budget_assignment')
        ->value('id');
    expect($conflictId)->toBeGreaterThan(0);

    Livewire::actingAs($this->user)
        ->test(PreviewMigration::class, ['id' => $reconciliationRun->id])
        ->call('resolveConflict', $conflictId, 'take_source')
        ->call('resolveConflict', $conflictId, 'keep_local');

    $resolution = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('id', $conflictId)
        ->value('resolution');
    expect($resolution)->toBe('keep_local');

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    $groceriesAssigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');

    // The last choice wins: 300.00 survives despite the take_source in between.
    expect((int) $groceriesAssigned)->toBe(30000);
});

it('ThreeWayMerge: PreviewMigration::resolveConflict() persists the chosen resolution for the correct conflict row', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    $conflictId = (int) $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->where('entity_type', 'budget_assignment')
        ->value('id');
    expect($conflictId)->toBeGreaterThan(0);

    Livewire::actingAs($this->user)
        ->test(PreviewMigration::class, ['id' => $reconciliationRun->id])
        ->call('resolveConflict', $conflictId, 'take_source')
        ->assertOk();

    $resolution = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('id', $conflictId)
        ->value('resolution');
    expect($resolution)->toBe('take_source');
});

it('ThreeWayMerge: the conflict row renders formatted currency and a human label, not raw minor units or an internal field name', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    $response = $this->actingAs($this->user)->get("/migrations/{$reconciliationRun->id}/preview");

    $response->assertOk();
    // Formatted currency, e.g. "€ 300,00" — never a raw minor-unit integer.
    $response->assertSee('€', false);
    $response->assertSee('Groceries', false);
    $response->assertDontSee('Budget_assignment budgeted_minor', false);
});

it('ThreeWayMerge: an unrecognised stored resolution reads as keep_local in the preview, and resolveConflict refuses to write one', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $jan = CarbonImmutable::parse('2026-01-01');
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $jan, 30000);

    $reconciliationRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v2'),
    );

    $conflictId = (int) $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $reconciliationRun->id)
        ->where('item_type', 'conflict')
        ->where('entity_type', 'budget_assignment')
        ->value('id');
    expect($conflictId)->toBeGreaterThan(0);

    Livewire::actingAs($this->user)
        ->test(PreviewMigration::class, ['id' => $reconciliationRun->id])
        ->call('resolveConflict', $conflictId, 'take_everything')
        ->assertOk();

    expect($this->db->connection()->table('migration_staging_unmapped_items')->where('id', $conflictId)->value('resolution'))->toBeNull();

    $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('id', $conflictId)
        ->update(['resolution' => 'take_everything']);

    $summary = app(PreviewSummaryBuilder::class)->forRun($reconciliationRun->id, $this->user);
    $row = collect($summary->unmapped['conflict']['items'])->firstWhere('id', $conflictId);

    expect($row)->not->toBeNull()
        ->and($row['resolution'])->toBe('keep_local');

    app(ConfirmMigration::class)->__invoke($reconciliationRun->id, $this->user);

    // The preview said keep-local, so Confirm must leave the local 300,00 alone.
    $assigned = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    expect((int) $assigned)->toBe(30000);
});
