<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;

uses(RefreshDatabase::class);

// A budget cell's source-map key is `{categoryExternalId}|{period_start}`, and a
// category the reader named with a pipe puts a second one in the left half.
const PIPED_CATEGORY_NAME = 'Rent | Utilities';

function pipedCategoryExportDir(string $budgeted): string
{
    $dir = sys_get_temp_dir().'/ynab4-piped-category-'.uniqid('', true);
    mkdir($dir, 0755, true);

    $header = 'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"';
    file_put_contents(
        $dir.'/Home as of 2026-01-20 - Register.csv',
        $header."\nChecking,,,01/15/2026,Landlord,\"Frequent: ".PIPED_CATEGORY_NAME.'",Frequent,"'.PIPED_CATEGORY_NAME."\",,45.00,0.00,C,955.00\n",
    );
    file_put_contents(
        $dir.'/Home as of 2026-01-20 - Budget.csv',
        "Month,\"Category Group\",Category,Budgeted,Outflows,\"Category Balance\"\n2026-01,Frequent,\"".PIPED_CATEGORY_NAME."\",{$budgeted},45.00,155.00\n",
    );

    return $dir;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-piped-category-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('names the month and the category of a budget conflict whose category name carries a pipe, instead of throwing the whole preview away', function (): void {
    $firstRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        pipedCategoryExportDir('200.00'),
        'Home Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($firstRun->id, $this->user);

    $category = Category::query()
        ->where('user_id', $this->user->id)
        ->where('name', PIPED_CATEGORY_NAME)
        ->firstOrFail();
    app(EnvelopeWriter::class)->setAssigned($this->user, $category->id, CarbonImmutable::parse('2026-01-01'), 30000);

    $secondRun = app(CheckForUpdates::class)->__invoke(
        $firstRun->id,
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        pipedCategoryExportDir('250.00'),
    );

    $summary = app(PreviewSummaryBuilder::class)->forRun($secondRun->id, $this->user);

    expect($summary->unmapped['conflict']['count'])->toBe(1)
        ->and($summary->unmapped['conflict']['items'][0]['label'])->toBe(PIPED_CATEGORY_NAME.' · January 2026 budget');

    $this->actingAs($this->user)->get("/migrations/{$secondRun->id}/preview")->assertOk();
});
