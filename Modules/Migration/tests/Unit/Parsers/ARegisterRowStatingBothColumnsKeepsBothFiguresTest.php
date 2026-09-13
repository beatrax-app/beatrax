<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Parsers\Ynab4Parser;

uses(RefreshDatabase::class);

// A register row's figure is its inflow less its outflow. Reading the inflow
// alone whenever it was set discarded the outflow of any row carrying both,
// and a discarded outflow is money missing from the ledger with nothing on
// screen to show for it.
function bothColumnsExportDir(string $registerBody): string
{
    $dir = sys_get_temp_dir().'/migration-both-columns-'.uniqid('', true);
    mkdir($dir, 0755, true);
    file_put_contents(
        $dir.'/My Budget as of 2026-01-20 - Register.csv',
        'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"'."\n".$registerBody,
    );
    file_put_contents(
        $dir.'/My Budget as of 2026-01-20 - Budget.csv',
        'Month,"Category Group",Category,Budgeted,Outflows,"Category Balance"'."\n".'2026-01,Frequent,Groceries,200.00,45.00,155.00',
    );

    return $dir;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-both-columns-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

it('nets a row that states an outflow and an inflow rather than dropping one of them', function (): void {
    $rows = 'Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,5.00,C,955.00';

    $batch = app(Ynab4Parser::class)->parse(bothColumnsExportDir($rows), $this->user, 1);
    $transactions = iterator_to_array($batch->transactions);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->amount->toMinor())->toBe(-4000);
});

it('leaves a row that states one column alone', function (string $outflow, string $inflow, int $expected): void {
    $rows = sprintf(
        'Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,%s,%s,C,955.00',
        $outflow,
        $inflow,
    );

    $batch = app(Ynab4Parser::class)->parse(bothColumnsExportDir($rows), $this->user, 1);
    $transactions = iterator_to_array($batch->transactions);

    expect($transactions[0]->amount->toMinor())->toBe($expected);
})->with([
    'an outflow' => ['45.00', '0.00', -4500],
    'an inflow' => ['0.00', '2000.00', 200000],
    'a row with neither' => ['0.00', '0.00', 0],
    'a column the row left blank' => ['45.00', '', -4500],
]);
