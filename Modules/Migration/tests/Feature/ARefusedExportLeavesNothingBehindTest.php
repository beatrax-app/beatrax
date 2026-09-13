<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;

uses(RefreshDatabase::class);

// A migration reads one product's whole history against a format assumption
// made once, so a cell that will not read is evidence about its COLUMN. The
// three below each used to be read as something else instead: an absent budget,
// a year, and text — none of which announced itself anywhere.
const REFUSED_EXPORT_REGISTER_HEADER = 'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"';

const REFUSED_EXPORT_BUDGET_HEADER = 'Month,"Category Group",Category,Budgeted,Outflows,"Category Balance"';

function refusedExportDir(string $registerBody, string $budgetBody): string
{
    $dir = sys_get_temp_dir().'/migration-refused-export-'.uniqid('', true);
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/My Budget as of 2026-01-20 - Register.csv', REFUSED_EXPORT_REGISTER_HEADER."\n".$registerBody);
    file_put_contents($dir.'/My Budget as of 2026-01-20 - Budget.csv', REFUSED_EXPORT_BUDGET_HEADER."\n".$budgetBody);

    return $dir;
}

/**
 * @return array<string, int>
 */
function refusedExportRowCounts(DatabaseManager $db, User $user): array
{
    $tables = [
        'migration_runs',
        'migration_staging_categories',
        'migration_staging_accounts',
        'migration_staging_payees',
        'migration_staging_budget_assignments',
        'migration_staging_transactions',
        'migration_staging_unmapped_items',
        'categories',
        'accounts',
        'transactions',
        'envelope_assignments',
    ];

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = $db->connection()->table($table)->where('user_id', $user->id)->count();
    }

    return $counts;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-refused-export-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

// Every one of these staged something, or wrote something, before it failed —
// or never failed at all. The row counts are the half that proves it, since a
// refusal that still left a category behind is not a refusal.
it('refuses the whole export and stages nothing for a cell it cannot read', function (string $register, string $budget, string $column, string $value): void {
    $dir = refusedExportDir($register, $budget);

    expect(fn () => app(StartMigrationRun::class)($this->user, MigrationSourceProduct::Ynab4->value, $dir, 'export.zip'))
        ->toThrow(UnrecognizedMigrationFileException::class);

    try {
        app(StartMigrationRun::class)($this->user, MigrationSourceProduct::Ynab4->value, $dir, 'export.zip');
    } catch (UnrecognizedMigrationFileException $e) {
        $cell = $e->refusedCell();
        expect($cell?->column)->toBe($column)
            ->and($cell?->value)->toBe($value);
    }

    expect(array_sum(refusedExportRowCounts($this->db, $this->user)))->toBe(0);
})->with([
    // Staged one budget row of two and reported nothing: the reader's January
    // budget was simply absent, and the preview called the import fully mapped.
    'a budget figure written with its currency beside it' => [
        'Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,955.00',
        "2026-01,Frequent,Groceries,\"€ 200,00\",45.00,155.00\n2026-02,Frequent,Groceries,100.00,0.00,100.00",
        'Budgeted',
        '€ 200,00',
    ],
    // A figure past the ceiling every stored amount obeys reads as no figure
    // at all through the same null, and dropped the month just as quietly.
    'a budget figure past the magnitude ceiling' => [
        'Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,955.00',
        '2026-01,Frequent,Groceries,9999999999.00,45.00,155.00',
        'Budgeted',
        '9999999999.00',
    ],
    // createFromFormat()'s Y takes two digits as readily as four, so this row
    // used to post itself on 15 January in the year 26 without a warning.
    'a date whose year is two digits' => [
        'Checking,,,01/15/26,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,955.00',
        '2026-01,Frequent,Groceries,200.00,45.00,155.00',
        'Date',
        '01/15/26',
    ],
]);

// This one reached promotion rather than parsing: the bytes travelled as a
// counterparty name into another module's slug resolver and threw there, with
// the categories and the budget grid already written and no way back.
it('refuses a cell that is not text before anything downstream takes it for text', function (): void {
    $register = "Checking,,,01/15/2026,\"Albert\xC3\x28 Heijn\",\"Frequent: Groceries\",Frequent,Groceries,,45.00,0.00,C,955.00";
    $dir = refusedExportDir($register, '2026-01,Frequent,Groceries,200.00,45.00,155.00');

    try {
        app(StartMigrationRun::class)($this->user, MigrationSourceProduct::Ynab4->value, $dir, 'export.zip');
        $this->fail('the export was accepted');
    } catch (UnrecognizedMigrationFileException $e) {
        expect($e->refusedCell()?->file)->toBe('Register.csv')
            ->and($e->refusedCell()?->column)->toBe('Payee');
    }

    expect(array_sum(refusedExportRowCounts($this->db, $this->user)))->toBe(0);
});
