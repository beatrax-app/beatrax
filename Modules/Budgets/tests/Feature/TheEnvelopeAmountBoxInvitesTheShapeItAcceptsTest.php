<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

// The grid folds every envelope into the reader's reporting currency and the
// cell is parsed at that currency, but the cell spelled two decimals and asked
// the phone for a decimal key whatever the reporting currency was.

/**
 * @return array{0: User, 1: Category}
 */
function envelopeShapeFixture(string $baseCurrency): array
{
    $user = User::create([
        'username' => 'envelope-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $baseCurrency,
    ]);

    DB::table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'envelope-shape-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    return [$user, $category];
}

function envelopeAmountBoxes(string $html): string
{
    preg_match_all('/<input[^>]*wire:model="(?:assignedInputs\.\d+|moveAmount)"[^>]*>/', $html, $found);

    expect($found[0])->not->toBe([], 'no envelope amount box rendered');

    return implode("\n", $found[0]);
}

it('invites a whole envelope figure from a yen reader', function (): void {
    [$user, $category] = envelopeShapeFixture('JPY');

    $html = Livewire::actingAs($user)
        ->test(BudgetsPage::class)
        ->call('openMove', $category->id)
        ->html();

    expect(envelopeAmountBoxes($html))
        ->toContain('placeholder="0"')
        ->not->toContain('placeholder="0.00"')
        ->toContain('inputmode="numeric"')
        ->not->toContain('inputmode="decimal"');
});

// A lock, not the red for this change: the cell a euro reader gets is
// byte-identical either side of it, because the old grid spelled `decimal` and
// the `0.00` placeholder key as literals. The yen half above is the proof;
// this half catches a currency-aware rewrite that loses the majority case.
it('locks the two decimals a euro reader already invited', function (): void {
    [$user, $category] = envelopeShapeFixture('EUR');

    $html = Livewire::actingAs($user)
        ->test(BudgetsPage::class)
        ->call('openMove', $category->id)
        ->html();

    expect(envelopeAmountBoxes($html))
        ->toContain('placeholder="0.00"')
        ->toContain('inputmode="decimal"')
        ->not->toContain('inputmode="numeric"');
});
