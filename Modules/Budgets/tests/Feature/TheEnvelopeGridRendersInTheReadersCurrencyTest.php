<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// /settings writes the reader's reporting currency to users.base_currency and
// the grid formats every figure through BaseCurrency. A reader on dollars was
// shown euro signs over dollar totals on every roll-up in the app; the grid is
// the surface that measured it.

function readerGridCurrencySetUp(string $username, ?string $chosen): array
{
    $user = User::create([
        'username' => $username.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        ...($chosen === null ? [] : ['base_currency' => $chosen]),
    ]);

    DB::table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
        ...($chosen === null ? ['base_currency' => null] : []),
    ]);

    $category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'rgc-groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    return [$user->fresh(), $category];
}

it('prints the grid under the sign of the currency the reader chose', function (): void {
    [$user, $category] = readerGridCurrencySetUp('rgc-usd', Currency::Usd->value);

    $html = Livewire::actingAs($user)
        ->test(BudgetsPage::class)
        ->set("assignedInputs.{$category->id}", '50.00')
        ->call('setAssigned', $category->id)
        ->html();

    expect($html)->toContain(Money::ofMinor(5000, Currency::Usd->value)->format())
        ->and($html)->not->toContain(Money::ofMinor(5000, Currency::Eur->value)->format());
});

// The euro reader is the case that was always green. Kept so the fix cannot be
// mistaken for having swapped which currency is hardcoded.
it('keeps printing euro for the reader who chose euro', function (): void {
    [$user, $category] = readerGridCurrencySetUp('rgc-eur', Currency::Eur->value);

    $html = Livewire::actingAs($user)
        ->test(BudgetsPage::class)
        ->set("assignedInputs.{$category->id}", '50.00')
        ->call('setAssigned', $category->id)
        ->html();

    expect($html)->toContain(Money::ofMinor(5000, Currency::Eur->value)->format());
});

// users.base_currency was added nullable with no backfill, so every user who
// existed before it has never chosen. config('currency.base') is what an
// install ships with, and it is what such a reader gets.
it('prints the install default for a reader whose row predates the setting', function (): void {
    [$user, $category] = readerGridCurrencySetUp('rgc-null', null);

    expect($user->base_currency)->toBeNull();

    $html = Livewire::actingAs($user)
        ->test(BudgetsPage::class)
        ->set("assignedInputs.{$category->id}", '50.00')
        ->call('setAssigned', $category->id)
        ->html();

    expect($html)->toContain(Money::ofMinor(5000, (string) config('currency.base'))->format());
});
