<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 12:00:00'));

    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'stranded-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 15,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-20 09:00:00']);
    $this->user->refresh();
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'stranded-g-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'stranded-d-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->genesis = app(CarryoverQuery::class)->genesisPeriodFor($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function runTheStrandedPlanRepair(): void
{
    $migration = require base_path('Modules/Budgets/Database/Migrations/2026_08_28_000002_lift_envelope_rows_stranded_below_genesis.php');
    $migration->up();
}

function seedAssignment(int $userId, int $categoryId, string $periodStart, int $minor): void
{
    DB::table('envelope_assignments')->insert([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'period_start' => $periodStart,
        'assigned_minor' => $minor,
        'currency' => 'EUR',
        'created_at' => '2026-06-20 09:00:00',
        'updated_at' => '2026-06-20 09:00:00',
    ]);
}

it('lifts a plan the old re-key left one period below genesis back onto genesis', function (): void {
    $stranded = app(PeriodQuery::class)->previous($this->genesis)->start->toDateString();
    seedAssignment($this->user->id, $this->groceries->id, $stranded, 40000);

    expect(app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->genesis)['rows'][$this->groceries->id]->assignedMinor)->toBe(0);

    runTheStrandedPlanRepair();

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('period_start'))
        ->toBe($this->genesis->start->toDateString());
    expect(app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->genesis)['rows'][$this->groceries->id]->assignedMinor)
        ->toBe(40000);
});

it('carries a move up with the assignment it belongs beside', function (): void {
    $stranded = app(PeriodQuery::class)->previous($this->genesis)->start->toDateString();
    DB::table('envelope_moves')->insert([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'counterpart_category_id' => $this->dining->id,
        'period_start' => $stranded,
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'kind' => 'move_out',
        'move_group_id' => 'stranded-group',
        'created_at' => '2026-06-20 09:00:00',
        'updated_at' => '2026-06-20 09:00:00',
    ]);

    runTheStrandedPlanRepair();

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->value('period_start'))
        ->toBe($this->genesis->start->toDateString());
});

// The UNIQUE is on (user_id, category_id, period_start), so the stranded month
// and the month sitting on genesis have to become one row, not two.
it('sums a stranded envelope into the genesis row it would collide with', function (): void {
    $stranded = app(PeriodQuery::class)->previous($this->genesis)->start->toDateString();
    seedAssignment($this->user->id, $this->groceries->id, $stranded, 40000);
    seedAssignment($this->user->id, $this->groceries->id, $this->genesis->start->toDateString(), 15000);

    runTheStrandedPlanRepair();

    $rows = DB::table('envelope_assignments')->where('user_id', $this->user->id)->get();
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->assigned_minor)->toBe(55000);
    expect((string) $rows[0]->period_start)->toBe($this->genesis->start->toDateString());
});

// Two periods down is further than the defect could ever reach, so a row there
// is one the reader put there and the repair has no business moving it.
it('leaves a row further below genesis than the defect could reach alone', function (): void {
    $periods = app(PeriodQuery::class);
    $farBelow = $periods->previous($periods->previous($this->genesis))->start->toDateString();
    seedAssignment($this->user->id, $this->groceries->id, $farBelow, 9900);

    runTheStrandedPlanRepair();

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('period_start'))->toBe($farBelow);
});

it('leaves a fresh install alone, whose genesis follows its own earliest row', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => null]);
    $this->user->refresh();

    $earliest = app(PeriodQuery::class)->previous(app(PeriodQuery::class)->current())->start->toDateString();
    seedAssignment($this->user->id, $this->groceries->id, $earliest, 40000);

    runTheStrandedPlanRepair();

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('period_start'))->toBe($earliest);
});
