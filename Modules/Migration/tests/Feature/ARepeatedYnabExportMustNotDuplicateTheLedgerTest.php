<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;

uses(RefreshDatabase::class);

// A YNAB register CSV carries no per-transaction id, so the reader's second
// export is matched against the first by what each row says. The file position
// is not that: one older transaction typed in between the two exports pushes
// every row below it down by one.
function ynabExportDir(string $registerBody): string
{
    $dir = sys_get_temp_dir().'/ynab4-reimport-'.uniqid('', true);
    mkdir($dir, 0755, true);

    $header = 'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"';
    file_put_contents($dir.'/My Budget as of 2026-01-20 - Register.csv', $header."\n".$registerBody);
    file_put_contents(
        $dir.'/My Budget as of 2026-01-20 - Budget.csv',
        "Month,\"Category Group\",Category,Budgeted,Outflows,\"Category Balance\"\n2026-01,Frequent,Groceries,200.00,45.00,155.00\n",
    );

    return $dir;
}

/**
 * @return list<string>
 */
function ledgerDatesAndAmounts(DatabaseManager $db, User $user): array
{
    return $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->orderBy('posted_at')
        ->orderBy('amount_minor')
        ->get(['posted_at', 'amount_minor'])
        ->map(fn (object $row): string => substr((string) $row->posted_at, 0, 10).' '.$row->amount_minor)
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-reimport-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);

    $this->firstExport = <<<'CSV'
    Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,955.00
    Checking,,,01/19/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,15.00,0.00,C,940.00
    Checking,,,01/16/2026,Employer,"Income: Salary",Income,Salary,,0.00,2000.00,N,2955.00

    CSV;
});

it('a re-export with one row added above the rest leaves every already-imported transaction on its own amount and date', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        ynabExportDir($this->firstExport),
        'My Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    expect(ledgerDatesAndAmounts($this->db, $this->user))->toBe([
        '2026-01-15 -4500',
        '2026-01-16 200000',
        '2026-01-19 -1500',
    ]);

    $secondExport = "Checking,,,01/10/2026,Bakery,\"Frequent: Groceries\",Frequent,Groceries,,7.00,0.00,C,993.00\n".$this->firstExport;

    $secondRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        ynabExportDir($secondExport),
    );
    app(ConfirmMigration::class)->__invoke($secondRun->id, $this->user);

    expect(ledgerDatesAndAmounts($this->db, $this->user))->toBe([
        '2026-01-10 -700',
        '2026-01-15 -4500',
        '2026-01-16 200000',
        '2026-01-19 -1500',
    ]);
});

it('a genuine second identical charge on one day is still imported as its own transaction', function (): void {
    $twiceInOneDay = <<<'CSV'
    Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,955.00
    Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,910.00

    CSV;

    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        ynabExportDir($twiceInOneDay),
        'My Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    expect(ledgerDatesAndAmounts($this->db, $this->user))->toBe([
        '2026-01-15 -4500',
        '2026-01-15 -4500',
    ]);
});
