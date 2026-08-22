<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// The staged kind is carried through to categories.kind unchanged, so the
// parsers must speak the Ledger vocabulary rather than the source product's:
// Actual's is_income flag is a boolean, and the value it maps to is ours.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'staged-kind-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('stages an Actual income category under the kind the Ledger enum names', function (): void {
    $zipPath = sys_get_temp_dir().'/staged-kind-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);
    $extracted = MigrationFixturePaths::extractZip($zipPath);

    $run = app(StartMigrationRun::class)->__invoke($this->user, 'actual', $extracted, 'actual-export.zip');

    $kinds = DB::table('migration_staging_categories')
        ->where('migration_run_id', $run->id)
        ->pluck('kind', 'name');

    expect($kinds['Salary'] ?? null)->toBe(CategoryKind::Income->value)
        ->and($kinds['Groceries'] ?? null)->toBe(CategoryKind::Expense->value);

    @unlink($zipPath);
});

// categories.kind has no CHECK trigger, so the literal is the whole anchor:
// every row already on disk and every shipped default category carries it.
it('spells the category kinds the way the rows already on disk do', function (): void {
    expect(CategoryKind::Income->value)->toBe('income')
        ->and(CategoryKind::Expense->value)->toBe('expense')
        ->and(CategoryKind::Transfer->value)->toBe('transfer');
});
