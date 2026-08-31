<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopePeriodRekeyer;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 12:00:00'));

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

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Two saves: the first press only raises the question the re-filing of every
// envelope row now has to be answered before it happens.
function movePeriodStartDayTo(int $day): void
{
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', $day)
        ->call('save')
        ->call('save')
        ->assertHasNoErrors();
}

// The cutover migration stamps this on every upgrader, and it is the anchor
// CarryoverQuery folds forward from — a fresh install carries null.
function activateEnvelopesAt(User $user, string $instant): void
{
    DB::table('users')->where('id', $user->id)->update(['envelope_activated_at' => $instant]);
    $user->refresh();
}

/**
 * @return array<int, int>
 */
function assignedInTheMonthTheReaderIsLookingAt(User $user): array
{
    $rows = app(CarryoverQuery::class)->forUserAndPeriod($user, app(PeriodQuery::class)->current())['rows'];

    $assigned = [];
    foreach ($rows as $row) {
        $assigned[$row->categoryId] = $row->assignedMinor;
    }

    return $assigned;
}

it('keeps a fresh install\'s plan on the month the reader is looking at after the budget month moves to payday', function (int $newDay): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $before->start, 15000);

    movePeriodStartDayTo($newDay);
    $this->user->refresh();

    $assigned = assignedInTheMonthTheReaderIsLookingAt($this->user);
    expect($assigned[$this->groceries->id] ?? 0)->toBe(40000);
    expect($assigned[$this->dining->id] ?? 0)->toBe(15000);

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(2);
})->with([2, 5, 15, 20, 25, 28]);

it('keeps an upgrader\'s plan on the month the reader is looking at after the budget month moves to payday', function (int $newDay): void {
    activateEnvelopesAt($this->user, '2026-06-20 09:00:00');

    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $before->start, 15000);

    movePeriodStartDayTo($newDay);
    $this->user->refresh();

    $assigned = assignedInTheMonthTheReaderIsLookingAt($this->user);
    expect($assigned[$this->groceries->id] ?? 0)->toBe(40000);
    expect($assigned[$this->dining->id] ?? 0)->toBe(15000);
})->with([2, 5, 15, 20, 21, 25, 28]);

it('gives the plan back when the reader moves the day again, and moves it back', function (): void {
    activateEnvelopesAt($this->user, '2026-06-20 09:00:00');

    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);

    foreach ([15, 25, 1] as $day) {
        movePeriodStartDayTo($day);
        $this->user->refresh();

        expect(assignedInTheMonthTheReaderIsLookingAt($this->user)[$this->groceries->id] ?? 0)
            ->toBe(40000, 'the plan vanished after moving the budget month to day '.$day);
    }

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('period_start'))
        ->toBe($before->start->toDateString());
});

// batchAssignments/batchMoves filter on period_start >= genesis and month-back
// nav stops there, so a row keyed below it is money no reader can reach again.
it('never keys a row below the genesis the fold starts at, whichever day the reader came from', function (int $oldDay): void {
    activateEnvelopesAt($this->user, '2026-03-05 09:00:00');
    DB::table('users')->where('id', $this->user->id)->update(['period_start_day' => $oldDay]);
    $this->user->refresh();

    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $plan = [
        $periods->previous($periods->previous($current))->start->toDateString() => 10000,
        $periods->previous($current)->start->toDateString() => 20000,
        $current->start->toDateString() => 40000,
    ];
    foreach ($plan as $periodStart => $minor) {
        app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, CarbonImmutable::parse($periodStart), $minor);
    }
    $totalBefore = (int) DB::table('envelope_assignments')->where('user_id', $this->user->id)->sum('assigned_minor');

    foreach ([1, 2, 14, 15, 20, 25, 28] as $newDay) {
        if ($newDay === $oldDay) {
            continue;
        }

        DB::table('users')->where('id', $this->user->id)->update(['period_start_day' => $newDay]);
        $this->user->refresh();
        app(EnvelopePeriodRekeyer::class)->rekeyToCurrentPeriods($oldDay);

        $genesis = app(CarryoverQuery::class)->genesisPeriodFor($this->user);
        expect($genesis)->not->toBeNull();

        $earliest = (string) DB::table('envelope_assignments')->where('user_id', $this->user->id)->min('period_start');
        expect($earliest)->toBeGreaterThanOrEqual(
            $genesis->start->toDateString(),
            "day {$oldDay} -> {$newDay} keyed a row below genesis {$genesis->start->toDateString()}",
        );

        expect((int) DB::table('envelope_assignments')->where('user_id', $this->user->id)->sum('assigned_minor'))
            ->toBe($totalBefore, "day {$oldDay} -> {$newDay} did not conserve the plan");

        expect(assignedInTheMonthTheReaderIsLookingAt($this->user)[$this->groceries->id] ?? 0)
            ->toBe(40000, "day {$oldDay} -> {$newDay} left the current month unbudgeted");

        DB::table('users')->where('id', $this->user->id)->update(['period_start_day' => $oldDay]);
        $this->user->refresh();
        app(EnvelopePeriodRekeyer::class)->rekeyToCurrentPeriods($newDay);
    }
})->with([1, 5, 15, 20, 28]);

// A row already below genesis was unreadable before the move too; the floor
// that stops a shift crossing genesis must not lift it into view instead.
it('leaves a row that was already below genesis where it was', function (): void {
    activateEnvelopesAt($this->user, '2026-06-20 09:00:00');

    $periods = app(PeriodQuery::class);
    $strandedPeriod = $periods->previous($periods->previous($periods->current()));
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $strandedPeriod->start, 9900);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $periods->current()->start, 40000);

    movePeriodStartDayTo(15);
    $this->user->refresh();

    $genesis = app(CarryoverQuery::class)->genesisPeriodFor($this->user);
    $stranded = DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->groceries->id)
        ->value('period_start');

    expect((string) $stranded)->toBeLessThan($genesis->start->toDateString());
    expect(assignedInTheMonthTheReaderIsLookingAt($this->user)[$this->dining->id] ?? 0)->toBe(40000);
});

it('carries a move across with its envelope so the pair still nets to zero', function (): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40000);
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $before->start, 5000, null);

    movePeriodStartDayTo(15);
    $this->user->refresh();

    $current = app(PeriodQuery::class)->current();

    $moves = DB::table('envelope_moves')->where('user_id', $this->user->id)->get();
    expect($moves)->toHaveCount(2);
    foreach ($moves as $move) {
        expect($move->period_start)->toBe($current->start->toDateString());
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
