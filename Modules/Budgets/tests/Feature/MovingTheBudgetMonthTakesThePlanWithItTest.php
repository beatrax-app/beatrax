<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'rekey-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'rekey-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'rekey-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
});

function movePeriodStartDayTo(int $day): void
{
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', $day)
        ->call('save')
        ->assertHasNoErrors();
}

it('keeps every assigned envelope readable after the budget month moves to payday', function (): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $before->start, 15000);

    movePeriodStartDayTo(15);

    $this->user->refresh();
    $after = app(PeriodQuery::class)->containingDate($before->start->toDateString());
    expect($after)->not->toBeNull();
    expect($after->start->toDateString())->not->toBe($before->start->toDateString());

    $rows = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $after)['rows'];
    $assigned = [];
    foreach ($rows as $row) {
        $assigned[$row->categoryId] = $row->assignedMinor;
    }

    expect($assigned[$this->groceries->id] ?? 0)->toBe(40000);
    expect($assigned[$this->dining->id] ?? 0)->toBe(15000);

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(2);
    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->where('period_start', $before->start->toDateString())->count())->toBe(0);
});

it('carries a move across with its envelope so the pair still nets to zero', function (): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $before->start, 5000, null);

    movePeriodStartDayTo(15);

    $this->user->refresh();
    $after = app(PeriodQuery::class)->containingDate($before->start->toDateString());

    $moves = DB::table('envelope_moves')->where('user_id', $this->user->id)->get();
    expect($moves)->toHaveCount(2);
    foreach ($moves as $move) {
        expect($move->period_start)->toBe($after->start->toDateString());
    }
    expect($moves->sum('amount_minor'))->toBe(0);
    expect($moves->pluck('move_group_id')->unique())->toHaveCount(1);
});

it('leaves the stored rows alone when the day is saved unchanged', function (): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);
    $idBefore = DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('id');

    movePeriodStartDayTo(1);

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('id'))->toBe($idBefore);
});
