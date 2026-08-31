<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

// Qualifying a leaf with its group answers "Groceries" against
// "Frequent › Groceries". It cannot answer a second top-level "Income", nor a
// "Salary" under a second "Income": the qualified path is byte-identical and
// there is no further ancestor to add. This is the shape a reader gets by
// importing any budgeting app that ships an Income → Salary pair, and every
// picker below assigns money.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'samepath-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));

    $this->seededGroup = Category::create([
        'user_id' => null,
        'name' => 'Income',
        'slug' => 'samepath-income-'.$suffix,
        'kind' => 'income',
        'display_order' => 1,
        'name_is_default' => true,
    ]);

    $this->seededLeaf = Category::create([
        'user_id' => null,
        'parent_id' => $this->seededGroup->id,
        'name' => 'Salary',
        'slug' => 'samepath-salary-'.$suffix,
        'kind' => 'income',
        'display_order' => 2,
        'name_is_default' => true,
    ]);

    $this->ownGroup = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Income',
        'slug' => 'samepath-income-own-'.$suffix,
        'kind' => 'income',
        'display_order' => 3,
    ]);

    $this->ownLeaf = Category::create([
        'user_id' => $this->user->id,
        'parent_id' => $this->ownGroup->id,
        'name' => 'Salary',
        'slug' => 'samepath-salary-own-'.$suffix,
        'kind' => 'income',
        'display_order' => 4,
    ]);
});

it('hands the pickers four options and four different labels', function (): void {
    $options = app(CategoryOptionsQuery::class)->for($this->user);
    $paths = array_map(static fn (object $option): string => $option->path, $options);

    expect($paths)->toHaveCount(4)
        ->and(array_unique($paths))->toHaveCount(4);
});

it('leaves the category the reader always had under its own bare name', function (): void {
    $byId = [];
    foreach (app(CategoryOptionsQuery::class)->for($this->user) as $option) {
        $byId[$option->id] = $option->path;
    }

    expect($byId[$this->seededGroup->id])->toBe('Income')
        ->and($byId[$this->seededLeaf->id])->toBe('Income › Salary')
        ->and($byId[$this->ownGroup->id])->not->toBe('Income')
        ->and($byId[$this->ownLeaf->id])->not->toBe('Income › Salary');
});

it('keeps the picker in the order the query asked for', function (): void {
    $ids = array_map(static fn (object $option): int => $option->id, app(CategoryOptionsQuery::class)->for($this->user));

    expect($ids)->toBe([$this->seededGroup->id, $this->seededLeaf->id, $this->ownGroup->id, $this->ownLeaf->id]);
});

it('gives the rule builder no two options that read the same', function (): void {
    $html = Livewire::test(RuleFormModal::class)->assertOk()->html();

    $found = preg_match_all('/<option value="(\d+)">([^<]*)<\/option>/', $html, $matches, PREG_SET_ORDER);
    expect($found)->not->toBeFalse();

    $labels = [];
    foreach ($matches as $match) {
        $labels[(int) $match[1]] = trim($match[2]);
    }

    expect($labels)->not->toBeEmpty()
        ->and(array_count_values(array_values($labels)))->each->toBe(1);
});
