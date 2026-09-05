<?php

declare(strict_types=1);

use Modules\Core\Public\Support\RefusedCell;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Internal\Parsers\Support\AmountStringParser;

// Every site that refuses a whole export over one cell composes the same three
// facts. They used to live only in a message the screen never shows and the log
// drops, so a reader was told the file could not be read and given nothing to
// look for in it.

function refusedCellFrom(Closure $refusing): RefusedCell
{
    try {
        $refusing();
    } catch (UnrecognizedMigrationFileException $e) {
        return $e->refusedCell() ?? throw new RuntimeException('the refusal named no cell: '.$e->getMessage());
    }

    throw new RuntimeException('the cell was accepted, so nothing was refused');
}

/**
 * @param  list<mixed>  $args
 */
function refusedCellFromParser(object $parser, string $method, array $args): RefusedCell
{
    return refusedCellFrom(fn (): mixed => (new ReflectionMethod($parser, $method))->invokeArgs($parser, $args));
}

it('names the register file and the column the figure was written in', function (string $column): void {
    $cell = refusedCellFrom(fn (): int => (new AmountStringParser)->requireMinor('twelve euros', 'Register.csv', $column));

    expect($cell->file)->toBe('Register.csv')
        ->and($cell->column)->toBe($column)
        ->and($cell->value)->toBe('twelve euros');
})->with(['Outflow', 'Inflow']);

it('names the YNAB file, column and value for a date and a month it cannot read', function (string $method, string $value, string $file, string $column): void {
    $cell = refusedCellFromParser(app(NynabParser::class), $method, [$value]);

    expect($cell->file)->toBe($file)
        ->and($cell->column)->toBe($column)
        ->and($cell->value)->toBe($value);
})->with([
    'a register date the calendar does not have' => ['parseRegisterDate', '02/31/2026', 'Register.csv', 'Date'],
    'a budget month the calendar does not have' => ['parseBudgetMonth', '2026-13', 'Budget.csv', 'Month'],
]);

it('names the Actual database, table column and value it cannot read', function (string $method, int $value, string $column): void {
    $cell = refusedCellFromParser(app(ActualParser::class), $method, [$value]);

    expect($cell->file)->toBe('db.sqlite')
        ->and($cell->column)->toBe($column)
        ->and($cell->value)->toBe((string) $value);
})->with([
    'a transaction date the calendar does not have' => ['parseActualDate', 20260231, 'transactions.date'],
    'a budget month the calendar does not have' => ['parseBudgetMonth', 202613, 'zero_budgets/reflect_budgets.month'],
]);

// A refusal that is not about a cell has none to name, and must not invent one:
// the log would otherwise carry a file and a column nothing in the export ever
// disagreed about.
it('names no cell when the refusal was never about one', function (): void {
    $refusal = new UnrecognizedMigrationFileException('failed to extract archive contents');

    expect($refusal->refusedCell())->toBeNull();
});
