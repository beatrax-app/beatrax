<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Contracts\ParsesMigrationSource;
use Modules\Migration\Internal\Dto\MigrationBatch;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

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

it('NynabParser: parses the v1 golden ZIP fixture into a populated MigrationBatch', function (): void {
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

    $albertHeijnPayeeId = $batch->payees->first(static fn ($p) => $p->name === 'Albert Heijn')?->sourceExternalId;
    expect($albertHeijnPayeeId)->not->toBeNull();
    $albertHeijnTxCount = count(array_filter($transactions, static fn ($t) => $t->payeeSourceExternalId === $albertHeijnPayeeId));
    expect($albertHeijnTxCount)->toBe(2);

    $clearedCounts = array_count_values(array_map(static fn ($t) => $t->clearedStatus, $transactions));
    expect($clearedCounts['uncleared'] ?? 0)->toBe(1);
    expect($clearedCounts['reconciled'] ?? 0)->toBe(0);
});

it('NynabParser: rejects the corrupt fixture with UnrecognizedMigrationFileException, importing nothing partially', function (): void {
    $parser = app(NynabParser::class);
    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::corruptZip());

    expect(fn () => $parser->parse($extracted, $this->user, 1))
        ->toThrow(UnrecognizedMigrationFileException::class);
});
