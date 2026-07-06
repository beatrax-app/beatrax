<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\ConfirmMigration;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * RED Wave 0 stub (13.5-02 Task 3) pinning the end-to-end parse -> stage ->
 * confirm contract through the REAL public writers (Req 2/3/4/5/6/7/9).
 * `StartMigrationRun`/`ConfirmMigration` do not exist until Plans 05/06 —
 * every test below is EXPECTED to fail now (missing-class error).
 *
 * `StartMigrationRun::__invoke(User $user, string $sourceProduct, string
 * $extractedPath, string $originalFilename): MigrationRun` parses +
 * populates staging, returning the created run (status 'parsed').
 * `ConfirmMigration::__invoke(int $migrationRunId, User $user): void`
 * promotes staging to the real domain tables via the existing public
 * writers (EnvelopeWriter, RecordTransactions, SaveTransactionSplit,
 * ResolvesCounterparties, PairsTransferLegs, GoalWriter) and flips the run
 * to 'confirmed'.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-confirm-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

function confirmYnab4V1(User $user): MigrationRun
{
    $run = app(StartMigrationRun::class)->__invoke(
        $user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    app(ConfirmMigration::class)->__invoke($run->id, $user);

    return $run->refresh();
}

it('MigrationConfirm: category count/names land in Ledger — Req 2', function (): void {
    confirmYnab4V1($this->user);

    $names = Category::query()->where('user_id', $this->user->id)->pluck('name')->all();
    expect($names)->toContain('Groceries', 'Household', 'Salary');
});

it('WR-03: a grouped source category is promoted with a non-null parent_id onto a real parent Category', function (): void {
    confirmYnab4V1($this->user);

    $frequent = Category::query()->where('user_id', $this->user->id)->where('name', 'Frequent')->firstOrFail();
    $income = Category::query()->where('user_id', $this->user->id)->where('name', 'Income')->firstOrFail();
    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $household = Category::query()->where('user_id', $this->user->id)->where('name', 'Household')->firstOrFail();
    $salary = Category::query()->where('user_id', $this->user->id)->where('name', 'Salary')->firstOrFail();

    expect($frequent->parent_id)->toBeNull();
    expect($income->parent_id)->toBeNull();
    expect($groceries->parent_id)->toBe($frequent->id);
    expect($household->parent_id)->toBe($frequent->id);
    expect($salary->parent_id)->toBe($income->id);
});

it('MigrationConfirm: envelope_assignments exact-to-cent per (category, month), compared to stored rows only — Req 3', function (): void {
    confirmYnab4V1($this->user);

    $groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $household = Category::query()->where('user_id', $this->user->id)->where('name', 'Household')->firstOrFail();

    $janGroceries = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $groceries->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');
    $janHousehold = $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $household->id)
        ->where('period_start', '2026-01-01')
        ->value('assigned_minor');

    // Fixture's Budget.csv v1: Jan Groceries 200.00, Jan Household 100.00 —
    // asserted against the STORED row only, never the source's own
    // "Category Balance" carried-forward figure (which is explicitly NOT
    // imported per Req 3's acceptance criterion).
    expect((int) $janGroceries)->toBe(20000);
    expect((int) $janHousehold)->toBe(10000);
});

it('MigrationConfirm: split parent + N legs sum to parent, cleared mapping preserved — Req 4', function (): void {
    confirmYnab4V1($this->user);

    // The Supermarket split (20.00 Groceries + 10.00 Household = 30.00) —
    // located via its counterparty (Supermarket collapses to one
    // counterparty per Req 6) and its distinctive settled amount.
    $parent = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('settled_amount_minor', -3000)
        ->first();

    expect($parent)->not->toBeNull();

    $legs = $this->db->connection()->table('transaction_splits')
        ->where('user_id', $this->user->id)
        ->where('transaction_id', $parent->id)
        ->get();

    expect($legs)->toHaveCount(2);
    expect((int) $legs->sum('settled_amount_minor'))->toBe(-3000);

    // Cleared mapping: every fixture row in this split is 'C' -> 'cleared'.
    expect($parent->status)->toBe('cleared');
});

it('MigrationConfirm: transfer pair linked, no orphan — Req 5', function (): void {
    confirmYnab4V1($this->user);

    // The 100.00 transfer leg on each side.
    $outLeg = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('settled_amount_minor', -10000)
        ->first();
    $inLeg = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('settled_amount_minor', 10000)
        ->first();

    expect($outLeg)->not->toBeNull();
    expect($inLeg)->not->toBeNull();
    expect($outLeg->pair_transaction_id)->toBe($inLeg->id);
    expect($inLeg->pair_transaction_id)->toBe($outLeg->id);
});

it('MigrationConfirm: a payee on >=2 transactions collapses to exactly one counterparty — Req 6', function (): void {
    confirmYnab4V1($this->user);

    $albertHeijnTxs = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('counterparty_name', 'Albert Heijn')
        ->get();

    expect($albertHeijnTxs)->toHaveCount(2);

    $counterpartyIds = $albertHeijnTxs->pluck('counterparty_id')->unique();
    expect($counterpartyIds)->toHaveCount(1);
    expect($counterpartyIds->first())->not->toBeNull();

    $counterpartyCount = $this->db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->where('display_name', 'Albert Heijn')
        ->count();
    expect($counterpartyCount)->toBe(1);
});

it('MigrationConfirm: non-base currency stamped in source currency, no fx rate written — Req 7 (Actual fixture)', function (): void {
    $zipPath = sys_get_temp_dir().'/migration-confirm-actual-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $run = app(StartMigrationRun::class)->__invoke($this->user, 'actual', $extracted, 'actual-export.zip');
    app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    $usdTransaction = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('currency', 'USD')
        ->first();

    expect($usdTransaction)->not->toBeNull();
    expect($usdTransaction->settled_currency)->toBe('USD');
    // No FX conversion happens at import time — the migration preserves the
    // budget-file currency verbatim; beatrax's own FX engine (v1.3 Phase 1)
    // handles reporting-currency roll-up separately.
    expect($usdTransaction->fx_rate_used)->toBeNull();

    @unlink($zipPath);
});

it('MigrationConfirm: a re-run yields identical row counts across every promoted table — Req 9 idempotency', function (): void {
    confirmYnab4V1($this->user);

    $countsAfterFirst = [
        'categories' => Category::query()->where('user_id', $this->user->id)->count(),
        'envelope_assignments' => $this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->count(),
        'transactions' => $this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count(),
        'transaction_splits' => $this->db->connection()->table('transaction_splits')->where('user_id', $this->user->id)->count(),
        'counterparties' => $this->db->connection()->table('counterparties')->where('user_id', $this->user->id)->count(),
        'accounts' => $this->db->connection()->table('accounts')->where('user_id', $this->user->id)->count(),
    ];

    // Re-run the identical parse -> stage -> confirm pipeline against the
    // SAME source file.
    $secondRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($secondRun->id, $this->user);

    $countsAfterSecond = [
        'categories' => Category::query()->where('user_id', $this->user->id)->count(),
        'envelope_assignments' => $this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->count(),
        'transactions' => $this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count(),
        'transaction_splits' => $this->db->connection()->table('transaction_splits')->where('user_id', $this->user->id)->count(),
        'counterparties' => $this->db->connection()->table('counterparties')->where('user_id', $this->user->id)->count(),
        'accounts' => $this->db->connection()->table('accounts')->where('user_id', $this->user->id)->count(),
    ];

    expect($countsAfterSecond)->toBe($countsAfterFirst);
});

it('CR-02: two genuinely distinct same-day same-amount same-account transactions both survive promotion', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    // Clone the fixture's "Employer" salary row (2000.00 inflow, 01/16/2026,
    // Checking) into a second, genuinely distinct staged transaction: same
    // account/date/amount/currency/payee — the exact shape that used to
    // collide on FingerprintComposer's date-only dedup tuple (CR-02), since
    // none of the three migration parsers ever carry a transaction
    // time-of-day.
    $employerRow = $this->db->connection()->table('migration_staging_transactions')
        ->where('migration_run_id', $run->id)
        ->where('payee_source_external_id', 'Employer')
        ->firstOrFail();

    $clone = (array) $employerRow;
    unset($clone['id']);
    $clone['source_external_id'] = 'row-dup-employer-2';
    $this->db->connection()->table('migration_staging_transactions')->insert($clone);

    app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    $employerTransactions = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('counterparty_name', 'Employer')
        ->get();

    // BOTH survive as two separate transaction rows — neither is silently
    // dropped as a fingerprint duplicate of the other.
    expect($employerTransactions)->toHaveCount(2);

    $bookedAts = $employerTransactions->pluck('booked_at')->map(fn (mixed $v): string => (string) $v)->unique();
    expect($bookedAts)->toHaveCount(2);

    // No genuine collision was silently absorbed — the fingerprint-miss
    // "extra" unmapped-item path (the CR-02 defense-in-depth surfacing) must
    // NOT have fired for this pair, because both rows now get distinct
    // fingerprints via the bookedAt fix.
    $collisionItems = $this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $run->id)
        ->where('item_type', 'extra')
        ->where('display_label', 'like', 'Transaction:%')
        ->get();
    expect($collisionItems)->toBeEmpty();
});
