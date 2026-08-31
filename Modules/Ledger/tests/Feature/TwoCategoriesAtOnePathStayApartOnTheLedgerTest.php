<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Ledger\Internal\Services\TransactionFilterOptions;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Support\CategoryPathName;

// The ledger's category chip narrows what the reader is looking at, and the
// chip is the one place the two rows appear side by side without a transaction
// between them. Below it, the pure seam: what it does when a path repeats, and
// what it must not do when it does not.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'lgrpath-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $suffix = bin2hex(random_bytes(3));

    $this->seededGroup = Category::create([
        'user_id' => null,
        'name' => 'Income',
        'slug' => 'lgrpath-income-'.$suffix,
        'kind' => 'income',
        'display_order' => 1,
        'name_is_default' => true,
    ]);

    $this->seededLeaf = Category::create([
        'user_id' => null,
        'parent_id' => $this->seededGroup->id,
        'name' => 'Salary',
        'slug' => 'lgrpath-salary-'.$suffix,
        'kind' => 'income',
        'display_order' => 2,
        'name_is_default' => true,
    ]);

    $this->ownGroup = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Income',
        'slug' => 'lgrpath-income-own-'.$suffix,
        'kind' => 'income',
        'display_order' => 3,
    ]);

    $this->ownLeaf = Category::create([
        'user_id' => $this->user->id,
        'parent_id' => $this->ownGroup->id,
        'name' => 'Salary',
        'slug' => 'lgrpath-salary-own-'.$suffix,
        'kind' => 'income',
        'display_order' => 4,
    ]);
});

it('offers the ledger filter four chips and four different labels', function (): void {
    $options = app(TransactionFilterOptions::class)->categories($this->user->id);
    $names = array_column($options, 'name');

    expect($names)->toHaveCount(4)
        ->and(array_unique($names))->toHaveCount(4);
});

it('leaves a path that repeats nowhere exactly as it was', function (): void {
    expect(CategoryPathName::distinct([7 => 'Groceries', 3 => 'Frequent › Groceries']))
        ->toBe([3 => 'Frequent › Groceries', 7 => 'Groceries']);
});

it('keeps the lowest id bare and marks the ones that came after it', function (): void {
    $distinct = CategoryPathName::distinct([9 => 'Income', 2 => 'Income', 40 => 'Income']);

    expect($distinct[2])->toBe('Income')
        ->and($distinct[9])->not->toBe('Income')
        ->and($distinct[40])->not->toBe('Income')
        ->and($distinct[9])->not->toBe($distinct[40]);
});

it('does not hand back a label a real category already holds', function (): void {
    $distinct = CategoryPathName::distinct([1 => 'Income', 2 => 'Income', 3 => 'Income (2)']);

    expect(array_unique(array_values($distinct)))->toHaveCount(3);
});
