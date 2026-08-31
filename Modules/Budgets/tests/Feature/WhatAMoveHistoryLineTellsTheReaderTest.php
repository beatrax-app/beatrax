<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Dto\EnvelopeMoveRow;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-10 09:00:00');

    DB::table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    DB::table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-07-01',
        'rate' => '1.13590',
        'source' => 'ecb',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'move-line-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Usd->value,
    ]);
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-07-01 00:00:00']);
    $this->user->refresh();
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));
    $this->fuel = Category::create(['user_id' => null, 'name' => 'Fuel', 'slug' => 'move-fuel-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'move-dining-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->july = app(PeriodQuery::class)->containingDate('2026-07-01');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The fold converts a move written in a currency the reader has since left;
// the line that explains the move has to name the same money.
it('shows a move at what the envelope actually received, not at its stored number', function (): void {
    app(EnvelopeWriter::class)->move($this->user, $this->fuel->id, $this->dining->id, $this->july->start, 50000);

    DB::table('users')->where('id', $this->user->id)->update(['base_currency' => Currency::Eur->value]);
    $this->user->refresh();
    $this->actingAs($this->user);

    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->july);
    expect($fold['rows'][$this->dining->id]->netMovedMinor)->toBe(44018);

    $component = Livewire::actingAs($this->user)->test(BudgetsPage::class);

    /** @var array<int, list<EnvelopeMoveRow>> $recentMoves */
    $recentMoves = $component->viewData('recentMoves');
    $line = $recentMoves[$this->dining->id][0];

    expect($line->amountMinor)->toBe(44018)
        ->and($line->currency)->toBe(Currency::Eur->value);

    $component->assertDontSee(Money::ofMinor(50000, Currency::Eur->value)->format());
});

// The modal asks for a note and stores it. A note nothing renders is a field
// that quietly discards what the reader typed into it.
it('shows the note the reader wrote on the move', function (): void {
    app(EnvelopeWriter::class)->move($this->user, $this->fuel->id, $this->dining->id, $this->july->start, 2500, 'Covering the ramen');

    Livewire::actingAs($this->user)->test(BudgetsPage::class)->assertSee('Covering the ramen');
});
