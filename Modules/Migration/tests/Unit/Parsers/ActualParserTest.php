<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Public\Contracts\ParsesMigrationSource;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * RED Wave 0 stub (13.5-02 Task 3) pinning the ActualParser contract
 * (Req 1/2/3/4/5/7/8). ActualParser does not exist until Plan 04 — every
 * test below is EXPECTED to fail now (missing-class error), not pass.
 *
 * Req 8's scope decision (DOCUMENTED ASSUMPTION, see Plan 04's own
 * assumption comment): a FLAT categories.goal_def -> exactly one
 * MigrationGoalDto; a NON-FLAT (template) goal_def, a saved-report
 * (`custom_reports`) config row, AND a `schedules` row all become
 * UnmappedItemDto('extra') entries — reported, never silently dropped.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'actual-parser-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $this->zipPath = sys_get_temp_dir().'/actual-parser-test-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($this->zipPath);
    $this->extracted = MigrationFixturePaths::extractZip($this->zipPath);
});

afterEach(function (): void {
    @unlink($this->zipPath);
});

it('ActualParser: format() returns actual', function (): void {
    $parser = app(ActualParser::class);

    expect($parser)->toBeInstanceOf(ParsesMigrationSource::class);
    expect($parser->format())->toBe('actual');
});

it('ActualParser: parses the golden fixture into a populated MigrationBatch — Req 1/2/3/4/5/7', function (): void {
    $parser = app(ActualParser::class);

    $batch = $parser->parse($this->extracted, $this->user, 1);

    expect($batch)->toBeInstanceOf(MigrationBatch::class);
    expect($batch->sourceProduct)->toBe('actual');

    // Req 7: budget-file-level currency preserved (the ONLY fixture that
    // meaningfully exercises non-EUR currency preservation, per RESEARCH's
    // documented YNAB single-currency-per-file limitation).
    expect($batch->budgetCurrency)->toBe(ActualFixtureBuilder::BUDGET_FILE_CURRENCY);
    expect($batch->budgetCurrency)->not->toBe('EUR');

    // Req 2/WR-03: 4 real categories (Groceries, Household, Salary, Emergency
    // Fund) + 2 category-group parents (Frequent, Income) materialized as
    // real parent Category rows.
    expect($batch->categories)->toHaveCount(6);
    expect($batch->categories->pluck('name')->all())->toContain('Groceries', 'Household', 'Salary', 'Emergency Fund', 'Frequent', 'Income');

    expect($batch->accounts)->toHaveCount(2);
    expect($batch->accounts->pluck('name')->all())->toContain('Checking', 'Savings');

    // Req 6: real payees only, transfer-account payees excluded.
    $payeeNames = $batch->payees->pluck('name')->all();
    expect($payeeNames)->toContain('Albert Heijn', 'Employer', 'Supermarket');
    expect($payeeNames)->not->toContain('Transfer: Savings', 'Transfer: Checking');

    // Req 3: 2 months x 2 budgeted categories = 4 assignment rows.
    expect($batch->budgetAssignments)->toHaveCount(4);

    // Req 4/5: 5 distinct transactions — plain expense, plain income, ONE
    // split parent (2 legs, from is_parent+2 is_child rows), and a
    // transfer PAIR (2 entries) — the tombstoned row is excluded entirely.
    $transactions = iterator_to_array($batch->transactions);
    expect($transactions)->toHaveCount(5);

    $splitParents = array_values(array_filter($transactions, static fn ($t) => $t->splits !== []));
    expect($splitParents)->toHaveCount(1);
    expect($splitParents[0]->splits)->toHaveCount(2);
    $legSum = array_sum(array_map(static fn (array $leg) => $leg['amount']->toMinor(), $splitParents[0]->splits));
    expect($legSum)->toBe($splitParents[0]->amount->toMinor());

    $transferLegs = array_values(array_filter($transactions, static fn ($t) => $t->transferCounterpartSourceExternalId !== null));
    expect($transferLegs)->toHaveCount(2);
});

it('ActualParser: a FLAT goal_def maps to exactly one MigrationGoalDto — Req 8', function (): void {
    $parser = app(ActualParser::class);
    $batch = $parser->parse($this->extracted, $this->user, 1);

    expect($batch->goals)->toHaveCount(1);
    expect($batch->goals->first()->categorySourceExternalId)->toBe('cat-groceries');
});

it('ActualParser: a NON-FLAT (template) goal_def becomes an UnmappedItemDto, never a lossy flat goal — Req 8', function (): void {
    $parser = app(ActualParser::class);
    $batch = $parser->parse($this->extracted, $this->user, 1);

    $goalCategoryIds = $batch->goals->pluck('categorySourceExternalId')->all();
    expect($goalCategoryIds)->not->toContain('cat-emergency-fund');

    $unmappedGoal = $batch->unmapped->first(
        static fn ($item) => $item->itemType === 'extra' && $item->sourceExternalId === 'cat-emergency-fund',
    );
    expect($unmappedGoal)->not->toBeNull();
});

it('ActualParser: the saved-report (custom_reports) row becomes an UnmappedItemDto, never silently dropped — Req 8', function (): void {
    $parser = app(ActualParser::class);
    $batch = $parser->parse($this->extracted, $this->user, 1);

    $unmappedReport = $batch->unmapped->first(
        static fn ($item) => $item->itemType === 'extra' && $item->sourceExternalId === 'report-1',
    );
    expect($unmappedReport)->not->toBeNull();
});

it('ActualParser: a schedules row becomes an UnmappedItemDto AND a preserved note (Open Q4 note-only descope) — Req 8', function (): void {
    $parser = app(ActualParser::class);
    $batch = $parser->parse($this->extracted, $this->user, 1);

    expect($batch->schedules)->toHaveCount(1);
    $schedule = $batch->schedules->first();
    expect($schedule->sourceExternalId)->toBe('sched-1');
    expect($schedule->note)->not->toBeEmpty();

    $unmappedSchedule = $batch->unmapped->first(
        static fn ($item) => $item->itemType === 'extra' && $item->sourceExternalId === 'sched-1',
    );
    expect($unmappedSchedule)->not->toBeNull();
});

it('ActualParser: BOTH the non-flat goal and the saved-report extra appear in the unmapped set — neither is silently dropped (Req 8)', function (): void {
    $parser = app(ActualParser::class);
    $batch = $parser->parse($this->extracted, $this->user, 1);

    $unmappedIds = $batch->unmapped->pluck('sourceExternalId')->all();
    expect($unmappedIds)->toContain('cat-emergency-fund', 'report-1', 'sched-1');
});

it('ActualParser: rejects the corrupt fixture with UnrecognizedMigrationFileException — Req 1 reject-not-partial', function (): void {
    $parser = app(ActualParser::class);
    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::corruptZip());

    expect(fn () => $parser->parse($extracted, $this->user, 1))
        ->toThrow(UnrecognizedMigrationFileException::class);
});
