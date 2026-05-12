<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

it('seeds the full Dutch-aware default category tree on a fresh database', function (): void {
    expect(Category::query()->count())->toBe(0);

    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    // 13 top-level parents + 16 children = 29 rows.
    expect(Category::query()->count())->toBe(29);
});

it('is idempotent — re-running the seeder produces the same row count', function (): void {
    $seeder = $this->app->make(DefaultCategoryTreeSeeder::class);

    $seeder->run();
    $afterFirst = Category::query()->count();

    $seeder->run();
    $afterSecond = Category::query()->count();

    expect($afterFirst)->toBe(29)
        ->and($afterSecond)->toBe($afterFirst);
});

it('links every leaf category to a parent and never lists a leaf as its own parent', function (): void {
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    /** @var Category $salary */
    $salary = Category::query()->where('slug', 'income-salary')->firstOrFail();
    /** @var Category $income */
    $income = Category::query()->where('slug', 'income')->firstOrFail();
    expect($salary->parent_id)->toBe($income->id);

    /** @var Category $streaming */
    $streaming = Category::query()->where('slug', 'subscriptions-streaming')->firstOrFail();
    /** @var Category $subscriptions */
    $subscriptions = Category::query()->where('slug', 'subscriptions')->firstOrFail();
    expect($streaming->parent_id)->toBe($subscriptions->id);

    /** @var Category $internalTransfers */
    $internalTransfers = Category::query()->where('slug', 'transfers-internal')->firstOrFail();
    expect($internalTransfers->parent_id)->toBeNull();
    expect($internalTransfers->kind)->toBe('transfer');
});

it('persists the income / expense / transfer kind on every row', function (): void {
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    // 4 income (Income parent + Salary / Refunds / Other income).
    // 24 expense (10 parents + 14 leaves under Housing / Transport / Insurance / Subscriptions).
    // 1 transfer (Transfers (internal) parent).
    expect(Category::query()->where('kind', 'income')->count())->toBe(4);
    expect(Category::query()->where('kind', 'expense')->count())->toBe(24);
    expect(Category::query()->where('kind', 'transfer')->count())->toBe(1);
});

it('runs from the diederik:install command via the UserInstalled listener', function (): void {
    // Drive the install command end-to-end (NOT Event::fake — we want the
    // SeedDefaultCategoryTree listener to actually fire on UserInstalled).
    $exit = $this->app->make(ConsoleKernel::class)->call('diederik:install', [
        '--email' => 'wessel@example.com',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ]);

    expect($exit)->toBe(0);
    expect(Category::query()->count())->toBe(29);
});
