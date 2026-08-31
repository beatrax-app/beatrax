<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

function readerOnCurrency(string $code): User
{
    return User::create([
        'username' => 'migration-currency-'.mb_strtolower($code).'-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => $code,
    ]);
}

function yenExportDir(): string
{
    $dir = sys_get_temp_dir().'/ynab4-yen-'.uniqid('', true);
    mkdir($dir, 0755, true);

    $header = 'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"';
    file_put_contents(
        $dir.'/Yen as of 2026-01-20 - Register.csv',
        $header."\nChecking,,,01/15/2026,Konbini,\"Frequent: Groceries\",Frequent,Groceries,,4500,0,C,95500\n",
    );
    file_put_contents(
        $dir.'/Yen as of 2026-01-20 - Budget.csv',
        "Month,\"Category Group\",Category,Budgeted,Outflows,\"Category Balance\"\n2026-01,Frequent,Groceries,20000,4500,15500\n",
    );

    return $dir;
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
});

it('books a dollar reader\'s YNAB history in dollars, and imports their budget months instead of refusing every one', function (): void {
    $user = readerOnCurrency('USD');

    $run = app(StartMigrationRun::class)->__invoke(
        $user,
        MigrationSourceProduct::Ynab4->value,
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $user);

    expect($this->db->connection()->table('transactions')->where('user_id', $user->id)->distinct()->pluck('currency')->all())->toBe(['USD'])
        ->and($this->db->connection()->table('accounts')->where('user_id', $user->id)->distinct()->pluck('default_currency')->all())->toBe(['USD']);

    // v1's Budget.csv carries four category-months; a fixed EUR made every one
    // of them disagree with the reader's envelopes and none was written.
    expect($this->db->connection()->table('envelope_assignments')->where('user_id', $user->id)->count())->toBe(4);

    expect($this->db->connection()->table('migration_staging_unmapped_items')
        ->where('migration_run_id', $run->id)
        ->where('source_external_id', 'like', 'budget_currency|%')
        ->count())->toBe(0);
});

it('reads a yen cell at the yen\'s own scale, not at a hundredth of it', function (): void {
    $user = readerOnCurrency('JPY');

    $run = app(StartMigrationRun::class)->__invoke(
        $user,
        MigrationSourceProduct::Ynab4->value,
        yenExportDir(),
        'Yen Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $user);

    /** @var object $transaction */
    $transaction = $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->firstOrFail(['amount_minor', 'currency']);

    expect((int) $transaction->amount_minor)->toBe(-4500)
        ->and((string) $transaction->currency)->toBe('JPY');

    expect((int) $this->db->connection()->table('envelope_assignments')
        ->where('user_id', $user->id)
        ->value('assigned_minor'))->toBe(20000);
});
