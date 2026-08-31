<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;

uses(RefreshDatabase::class);

// Every budgeting app ships an Income → Salary pair, so an export carrying one
// lands on an install whose seeded tree already has it. Slug uniqueness did not
// see that: it walked the slug against the reader's OWN rows only, so the
// imported `income` never met the global `income` and the name was never
// compared at all. Two top-level Income rows and two Salary rows under them is
// not a tree the reader can use — nothing on either row tells them apart.

function samePathExportDir(): string
{
    $dir = sys_get_temp_dir().'/mig-samepath-'.uniqid('', true);
    mkdir($dir, 0755, true);

    $header = 'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"';
    $rows = <<<'CSV'
    Checking,,,01/16/2026,Employer,"Income: Salary",Income,Salary,,0.00,2000.00,N,2000.00
    Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,45.00,0.00,C,1955.00
    Checking,,,01/18/2026,Corner,"Groceries",,Groceries,,10.00,0.00,C,1945.00
    CSV;

    file_put_contents($dir.'/My Budget as of 2026-01-20 - Register.csv', $header."\n".$rows."\n");
    file_put_contents(
        $dir.'/My Budget as of 2026-01-20 - Budget.csv',
        "Month,\"Category Group\",Category,Budgeted,Outflows,\"Category Balance\"\n2026-01,Frequent,Groceries,200.00,45.00,155.00\n",
    );

    return $dir;
}

/**
 * @return array<int, string>
 */
function samePathVisibleCategoryPaths(DatabaseManager $db, User $user): array
{
    $rows = CategoryPathName::joinParent($db->connection()->table('categories as c'), $user->id, 'c', 'cp')
        ->where(static function (Builder $query) use ($user): void {
            $query->whereNull('c.user_id')->orWhere('c.user_id', $user->id);
        })
        ->get(['c.id', ...CategoryPathName::columns('c', 'cp')]);

    $paths = [];
    foreach ($rows as $row) {
        $paths[(int) $row->id] = CategoryPathName::fromRow($row) ?? '';
    }

    return $paths;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'mig-samepath-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);

    app(DefaultCategoryTreeSeeder::class)->run();

    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        samePathExportDir(),
        'My Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    $this->paths = samePathVisibleCategoryPaths($this->db, $this->user);
});

it('leaves the reader one Income and one Income salary, not two of each', function (): void {
    $counted = array_count_values(array_values($this->paths));

    expect($counted['Income'] ?? 0)->toBe(1)
        ->and($counted['Income'.CategoryPathName::SEPARATOR.'Salary'] ?? 0)->toBe(1);
});

it('files the imported salary in the category the reader already had', function (): void {
    $salaryId = (int) $this->db->connection()->table('categories')
        ->whereNull('user_id')->where('slug', 'income-salary')->value('id');

    expect($this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('amount_minor', 200000)
        ->value('category_id'))->toBe($salaryId);
});

it('still creates a leaf that only shares its name with one under a different group', function (): void {
    $groceries = array_keys($this->paths, 'Groceries', true);
    $grouped = array_keys($this->paths, 'Frequent'.CategoryPathName::SEPARATOR.'Groceries', true);

    expect($groceries)->toHaveCount(1)
        ->and($grouped)->toHaveCount(1)
        ->and($grouped[0])->not->toBe($groceries[0]);
});

it('reports nothing for the reader to decide about a category it matched', function (): void {
    expect($this->db->connection()->table('migration_staging_unmapped_items')
        ->where('user_id', $this->user->id)
        ->count())->toBe(0);
});
