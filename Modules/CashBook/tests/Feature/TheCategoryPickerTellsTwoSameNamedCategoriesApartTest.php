<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

// The picker is a flat alphabetical list of every visible category, so a leaf
// sits nowhere near its group and two groups' leaves collide outright. An
// <option>'s text is also its accessible name, so a duplicate label is a
// duplicate accessible name.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cbcollide-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $suffix = bin2hex(random_bytes(3));

    $this->group = Category::create([
        'user_id' => null,
        'name' => 'Frequent',
        'slug' => 'cbcollide-frequent-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->grouped = Category::create([
        'user_id' => null,
        'parent_id' => $this->group->id,
        'name' => 'Groceries',
        'slug' => 'cbcollide-grouped-'.$suffix,
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $this->standalone = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'cbcollide-standalone-'.$suffix,
        'kind' => 'expense',
        'display_order' => 3,
    ]);
});

/**
 * @return array<int, string>
 */
function cashBookPickerOptions(string $html): array
{
    $found = preg_match_all('/<option value="(\d+)">([^<]*)<\/option>/', $html, $matches, PREG_SET_ORDER);
    if ($found === false) {
        return [];
    }

    $options = [];
    foreach ($matches as $match) {
        $options[(int) $match[1]] = trim($match[2]);
    }

    return $options;
}

it('gives every category option its own label', function (): void {
    $options = cashBookPickerOptions(Livewire::actingAs($this->user)->test(CashBookPage::class)->assertOk()->html());

    expect($options)->not->toBeEmpty()
        ->and(array_count_values(array_values($options)))
        ->each->toBe(1);
});

it('names the group in front of a leaf that has one, and leaves a top-level category bare', function (): void {
    $options = cashBookPickerOptions(Livewire::actingAs($this->user)->test(CashBookPage::class)->assertOk()->html());

    expect($options)->toHaveKey($this->grouped->id)
        ->and($options[$this->grouped->id])->toContain('Frequent')
        ->and($options[$this->grouped->id])->toContain('Groceries')
        ->and($options)->toHaveKey($this->standalone->id)
        ->and($options[$this->standalone->id])->toBe('Groceries');
});
