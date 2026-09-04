<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Category;

// Read off a phone: 35 options, two of them "Income" and two of them
// "Income › Salary". The group in front of the leaf had already fixed
// "Groceries"; it has nothing left to add when the groups are named alike too.
// An <option>'s text is also its accessible name, so a duplicate label is a
// duplicate accessible name.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cbpath-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $suffix = bin2hex(random_bytes(3));

    $this->seededGroup = Category::create([
        'user_id' => null,
        'name' => 'Income',
        'slug' => 'cbpath-income-'.$suffix,
        'kind' => 'income',
        'display_order' => 1,
        'name_is_default' => true,
    ]);

    $this->seededLeaf = Category::create([
        'user_id' => null,
        'parent_id' => $this->seededGroup->id,
        'name' => 'Salary',
        'slug' => 'cbpath-salary-'.$suffix,
        'kind' => 'income',
        'display_order' => 2,
        'name_is_default' => true,
    ]);

    $this->ownGroup = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Income',
        'slug' => 'cbpath-income-own-'.$suffix,
        'kind' => 'income',
        'display_order' => 3,
    ]);

    $this->ownLeaf = Category::create([
        'user_id' => $this->user->id,
        'parent_id' => $this->ownGroup->id,
        'name' => 'Salary',
        'slug' => 'cbpath-salary-own-'.$suffix,
        'kind' => 'income',
        'display_order' => 4,
    ]);
});

/**
 * @return array<int, string>
 */
function cashBookPathOptions(string $html): array
{
    $matches = PatternScan::sets('/<option value="(\d+)">([^<]*)<\/option>/', $html);

    $options = [];
    foreach ($matches as $match) {
        $options[(int) $match[1]] = trim($match[2]);
    }

    return $options;
}

it('gives no two entries in the category picker the same label', function (): void {
    $options = cashBookPathOptions(Livewire::actingAs($this->user)->test(CashBookPage::class)->assertOk()->html());

    expect($options)->toHaveCount(4)
        ->and(array_count_values(array_values($options)))->each->toBe(1);
});

it('tells the two salaries apart without moving the one that was already there', function (): void {
    $options = cashBookPathOptions(Livewire::actingAs($this->user)->test(CashBookPage::class)->assertOk()->html());

    expect($options[$this->seededLeaf->id])->toBe('Income › Salary')
        ->and($options[$this->ownLeaf->id])->toContain('Income › Salary')
        ->and($options[$this->ownLeaf->id])->not->toBe('Income › Salary');
});
