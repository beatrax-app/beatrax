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
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\ValueObjects\Money;

// The budget row puts a read-only amount and an editable one side by side. The
// help copy on the language picker says the language decides how amounts are
// written, so both of them have to answer to it — not just the read-only half.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'amount-marks-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'amount-marks-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    app(EnvelopeWriter::class)->setAssigned(
        $this->user,
        $this->groceries->id,
        app(PeriodQuery::class)->current()->start,
        5000,
    );
});

it('pre-fills the assign box with the marks the reader’s own figures use', function (string $locale, string $expected): void {
    App::setLocale($locale);

    Livewire::test(BudgetsPage::class)
        ->assertSet("assignedInputs.{$this->groceries->id}", $expected);
})->with([
    ['en', '50.00'],
    ['nl', '50,00'],
]);

// Stated as the invariant rather than as two literals: the editable figure is
// the read-only one with its symbol taken off, in every language.
it('never puts a differently written figure in the same row', function (): void {
    $mismatched = [];

    foreach (['en', 'nl', 'de', 'fr', 'fi', 'sv'] as $locale) {
        App::setLocale($locale);

        $prefilled = (string) Livewire::test(BudgetsPage::class)->get("assignedInputs.{$this->groceries->id}");
        $readOnly = Money::ofMinor(5000, 'EUR')->format();

        if (! str_contains($readOnly, $prefilled)) {
            $mismatched[$locale] = $readOnly.' vs '.$prefilled;
        }
    }

    expect($mismatched)->toBe([]);
});
