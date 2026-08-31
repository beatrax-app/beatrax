<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

beforeEach(function (): void {
    App::setLocale('en');
    $this->user = User::create([
        'username' => 'yen-budget-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'yen-budget-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    $this->eatingOut = Category::create([
        'user_id' => null,
        'name' => 'Eating out',
        'slug' => 'yen-budget-eating-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 2,
    ]);
});

it('writes a typed yen budget as whole yen', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set("assignedInputs.{$this->groceries->id}", '50000')
        ->call('setAssigned', $this->groceries->id);

    $stored = DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->groceries->id)
        ->first(['assigned_minor', 'currency']);

    expect((int) $stored->assigned_minor)->toBe(50_000)
        ->and($stored->currency)->toBe(Currency::Jpy->value);
});

it('reads a stored yen budget back into the box as whole yen', function (): void {
    app(EnvelopeWriter::class)->setAssigned(
        $this->user,
        $this->groceries->id,
        app(PeriodQuery::class)->current()->start,
        50_000,
    );

    Livewire::test(BudgetsPage::class)
        ->assertSet("assignedInputs.{$this->groceries->id}", '50,000');
});

it('moves whole yen between two yen envelopes', function (): void {
    app(EnvelopeWriter::class)->setAssigned(
        $this->user,
        $this->groceries->id,
        app(PeriodQuery::class)->current()->start,
        50_000,
    );

    Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->groceries->id)
        ->set('moveToCategoryId', (string) $this->eatingOut->id)
        ->set('moveAmount', '12000')
        ->call('moveMoney')
        ->assertSet('moveError', '');

    $moved = DB::table('envelope_moves')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->eatingOut->id)
        ->value('amount_minor');

    expect((int) $moved)->toBe(12_000);
});
