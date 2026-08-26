<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\NynabParser;

// The ingestion adapters were taught to refuse a date createFromFormat() would
// roll forward rather than reject. The migration parsers read the same kind of
// user-supplied export and still took the roll: a thirteenth budget month
// filed itself in January of the following year.

/**
 * @param  list<mixed>  $args
 */
function callParser(object $parser, string $method, array $args): mixed
{
    $ref = new ReflectionMethod($parser, $method);

    return $ref->invokeArgs($parser, $args);
}

it('refuses an Actual date the calendar does not have', function (string $method, int $value): void {
    $parser = app(ActualParser::class);

    expect(fn () => callParser($parser, $method, [$value]))
        ->toThrow(UnrecognizedMigrationFileException::class);
})->with([
    'transactions.date 31 February' => ['parseActualDate', 20260231],
    'transactions.date 32 January' => ['parseActualDate', 20260132],
    'budget month thirteen' => ['parseBudgetMonth', 202613],
    'budget month zero' => ['parseBudgetMonth', 202600],
]);

it('keeps the Actual dates the parser already reads', function (string $method, int $value, string $expected): void {
    $parser = app(ActualParser::class);

    expect(callParser($parser, $method, [$value])->toDateString())->toBe($expected);
})->with([
    'transactions.date' => ['parseActualDate', 20260805, '2026-08-05'],
    'transactions.date on a leap day' => ['parseActualDate', 20280229, '2028-02-29'],
    'budget month' => ['parseBudgetMonth', 202608, '2026-08-01'],
]);

it('refuses a YNAB date the calendar does not have', function (string $method, string $value): void {
    $parser = app(NynabParser::class);

    expect(fn () => callParser($parser, $method, [$value]))
        ->toThrow(UnrecognizedMigrationFileException::class);
})->with([
    'Register.csv 31 February' => ['parseRegisterDate', '02/31/2026'],
    'Budget.csv month thirteen' => ['parseBudgetMonth', '2026-13'],
]);

it('keeps the YNAB dates the parser already reads', function (string $method, string $value, string $expected): void {
    $parser = app(NynabParser::class);

    expect(callParser($parser, $method, [$value])->toDateString())->toBe($expected);
})->with([
    'Register.csv' => ['parseRegisterDate', '08/05/2026', '2026-08-05'],
    'Register.csv on a leap day' => ['parseRegisterDate', '02/29/2028', '2028-02-29'],
    'Budget.csv iso month' => ['parseBudgetMonth', '2026-08', '2026-08-01'],
    'Budget.csv named month' => ['parseBudgetMonth', 'Aug 2026', '2026-08-01'],
    'Budget.csv slashed month' => ['parseBudgetMonth', '08/2026', '2026-08-01'],
]);
