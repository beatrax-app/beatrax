<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// The figures beside this filter are grouped by category, so a filter offering
// the same label twice narrows a report to a row the reader did not choose.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rbpath-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));

    $this->seeded = Category::create([
        'user_id' => null,
        'name' => 'Household',
        'slug' => 'rbpath-household-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
        'name_is_default' => true,
    ]);

    $this->own = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Household',
        'slug' => 'rbpath-household-own-'.$suffix,
        'kind' => 'expense',
        'display_order' => 2,
    ]);
});

it('offers no two category filters that read the same', function (): void {
    /** @var list<array{id: int, name: string}> $categories */
    $categories = Livewire::test(ReportBuilder::class)->viewData('availableCategories');
    $names = array_column($categories, 'name');

    expect($names)->toHaveCount(2)
        ->and(array_unique($names))->toHaveCount(2);
});

it('leaves the category the reader always had under its own bare name', function (): void {
    /** @var list<array{id: int, name: string}> $categories */
    $categories = Livewire::test(ReportBuilder::class)->viewData('availableCategories');
    $byId = array_column($categories, 'name', 'id');

    expect($byId[$this->seeded->id])->toBe('Household')
        ->and($byId[$this->own->id])->not->toBe('Household');
});
