<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Public\Contracts\ParsesMigrationSource;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

/*
 * RED Wave 0 stub (13.5-02 Task 3) pinning the NynabParser contract
 * (Req 1/2/3/4/5/6/7). NynabParser does not exist until Plan 03 — every
 * test below is EXPECTED to fail now (missing-class error), not pass.
 *
 * The scenario mirrors Ynab4ParserTest exactly (same underlying content,
 * packaged as a ZIP with the single combined "Category Group/Category"
 * column) — this file exists to pin that nynab's parser handles the ZIP
 * extraction step and the combined-column shape identically to Ynab4Parser's
 * loose-folder + Master/Sub Category handling.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'nynab-parser-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

it('NynabParser: format() returns nynab', function (): void {
    $parser = app(NynabParser::class);

    expect($parser)->toBeInstanceOf(ParsesMigrationSource::class);
    expect($parser->format())->toBe('nynab');
});

it('NynabParser: parses the v1 golden ZIP fixture into a populated MigrationBatch — Req 1/2/3/4/5/6/7', function (): void {
    $parser = app(NynabParser::class);
    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::nynabZip('v1'));

    $batch = $parser->parse($extracted, $this->user, 1);

    expect($batch)->toBeInstanceOf(MigrationBatch::class);
    expect($batch->sourceProduct)->toBe('nynab');

    expect($batch->categories)->not->toBeEmpty();
    expect($batch->categories->pluck('name')->all())->toContain('Groceries', 'Household', 'Salary');

    expect($batch->accounts)->not->toBeEmpty();
    expect($batch->accounts->pluck('name')->all())->toContain('Checking', 'Savings');

    expect($batch->payees)->not->toBeEmpty();
    $payeeNames = $batch->payees->pluck('name')->all();
    expect($payeeNames)->toContain('Albert Heijn', 'Employer', 'Supermarket');
    expect($payeeNames)->not->toContain('Transfer : Savings', 'Transfer : Checking');

    expect($batch->budgetAssignments)->toHaveCount(4);

    $transactions = iterator_to_array($batch->transactions);
    expect($transactions)->toHaveCount(6);

    $splitParents = array_values(array_filter($transactions, static fn ($t) => $t->splits !== []));
    expect($splitParents)->toHaveCount(1);
    expect($splitParents[0]->splits)->toHaveCount(2);

    $transferLegs = array_values(array_filter($transactions, static fn ($t) => $t->transferCounterpartSourceExternalId !== null));
    expect($transferLegs)->toHaveCount(2);

    // Req 6: Albert Heijn appears on exactly 2 distinct transactions.
    $albertHeijnPayeeId = $batch->payees->first(static fn ($p) => $p->name === 'Albert Heijn')?->sourceExternalId;
    expect($albertHeijnPayeeId)->not->toBeNull();
    $albertHeijnTxCount = count(array_filter($transactions, static fn ($t) => $t->payeeSourceExternalId === $albertHeijnPayeeId));
    expect($albertHeijnTxCount)->toBe(2);

    $clearedCounts = array_count_values(array_map(static fn ($t) => $t->clearedStatus, $transactions));
    expect($clearedCounts['uncleared'] ?? 0)->toBe(1);
    expect($clearedCounts['reconciled'] ?? 0)->toBe(0);
});

it('NynabParser: rejects the corrupt fixture with UnrecognizedMigrationFileException — Req 1 reject-not-partial', function (): void {
    $parser = app(NynabParser::class);
    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::corruptZip());

    expect(fn () => $parser->parse($extracted, $this->user, 1))
        ->toThrow(UnrecognizedMigrationFileException::class);
});
