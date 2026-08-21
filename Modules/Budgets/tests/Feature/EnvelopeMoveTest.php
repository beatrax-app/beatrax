<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeBalanceQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'move-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'move-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'move-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();

    // CarryoverQuery returns all zeros for a null `envelope_activated_at`, so
    // stamping the current period as the activation month keeps this file's
    // moves inside the fold without depending on the cutover migration.
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);
});

it('moves €20 A→B: A available drops €20, B available rises €20, to-budget is unchanged', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);

    $before = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    $beforeToBudget = $before['toBudgetMinor'];
    $beforeGroceries = $before['rows'][$this->groceries->id]->availableMinor;
    $beforeDining = $before['rows'][$this->dining->id]->availableMinor;

    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2000);

    $after = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);

    expect($after['rows'][$this->groceries->id]->availableMinor)->toBe($beforeGroceries - 2000);
    expect($after['rows'][$this->dining->id]->availableMinor)->toBe($beforeDining + 2000);
    expect($after['toBudgetMinor'])->toBe($beforeToBudget);

    $this->assertDatabaseHas('envelope_moves', ['category_id' => $this->groceries->id, 'counterpart_category_id' => $this->dining->id, 'amount_minor' => -2000, 'kind' => 'move_out']);
    $this->assertDatabaseHas('envelope_moves', ['category_id' => $this->dining->id, 'counterpart_category_id' => $this->groceries->id, 'amount_minor' => 2000, 'kind' => 'move_in']);
});

it('undoes a move, restoring both envelopes to their pre-move values', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);

    $before = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    $beforeGroceries = $before['rows'][$this->groceries->id]->availableMinor;
    $beforeDining = $before['rows'][$this->dining->id]->availableMinor;

    $moveId = app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2000);
    app(EnvelopeWriter::class)->undoMove($this->user, $moveId);

    $after = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);

    expect($after['rows'][$this->groceries->id]->availableMinor)->toBe($beforeGroceries);
    expect($after['rows'][$this->dining->id]->availableMinor)->toBe($beforeDining);
    expect(DB::table('envelope_moves')->where('category_id', $this->groceries->id)->where('counterpart_category_id', $this->dining->id)->count())->toBe(0);
});

it('permits a move that leaves the source envelope negative -- never balance-blocked', function (): void {
    // No assignment at all: Groceries available is 0 before the move.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);

    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2000);

    $after = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);

    expect($after['rows'][$this->groceries->id]->availableMinor)->toBe(-2000);
});

it('undoes the exact paired rows via move_group_id when two identical moves share a wall-clock second', function (): void {
    // Freeze the clock so both moves get an identical second-precision
    // created_at — the ambiguity that previously let undoMove() delete the
    // wrong counterpart.
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);

    $firstMoveId = app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2000);
    $secondMoveId = app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2000);

    $firstGroup = DB::table('envelope_moves')->where('id', $firstMoveId)->value('move_group_id');
    $secondGroup = DB::table('envelope_moves')->where('id', $secondMoveId)->value('move_group_id');
    expect($firstGroup)->not->toBeNull();
    expect($secondGroup)->not->toBeNull();
    expect($firstGroup)->not->toBe($secondGroup);

    app(EnvelopeWriter::class)->undoMove($this->user, $firstMoveId);

    expect(DB::table('envelope_moves')->where('move_group_id', $firstGroup)->count())->toBe(0);
    expect(DB::table('envelope_moves')->where('move_group_id', $secondGroup)->count())->toBe(2);
    expect(DB::table('envelope_moves')->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

it('rejects a move to the same source and destination envelope', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);

    expect(fn () => app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->groceries->id, $this->period->start, 1000))
        ->toThrow(InvalidArgumentException::class);
});

it('lists a single envelope\'s recent moves with a formatted timestamp via the per-category read seam', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2500, 'weekly top-up');

    $moves = app(EnvelopeBalanceQuery::class)
        ->recentMovesFor($this->user->id, $this->groceries->id, $this->period);

    expect($moves)->toHaveCount(1);
    expect($moves[0]->direction)->toBe('out');
    expect($moves[0]->amountMinor)->toBe(-2500);
    expect($moves[0]->counterpartCategoryId)->toBe($this->dining->id);
    expect($moves[0]->memo)->toBe('weekly top-up');
    expect($moves[0]->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/');
});

it('degrades an unparseable move timestamp to an empty string rather than throwing', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2500);

    DB::table('envelope_moves')
        ->where('category_id', $this->groceries->id)
        ->update(['created_at' => 'not-a-timestamp']);

    $moves = app(EnvelopeBalanceQuery::class)
        ->recentMovesFor($this->user->id, $this->groceries->id, $this->period);

    expect($moves)->toHaveCount(1);
    expect($moves[0]->createdAt)->toBe('');
});
