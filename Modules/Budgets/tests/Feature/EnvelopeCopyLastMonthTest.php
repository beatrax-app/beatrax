<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'copylast-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'copylast-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'copylast-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
    $this->fuel = Category::create(['user_id' => null, 'name' => 'Fuel', 'slug' => 'copylast-fuel-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 3]);

    $this->prior = app(PeriodQuery::class)->current();
    $this->selected = app(PeriodQuery::class)->next($this->prior);
});

it('reproduces the prior periods assigned amount for every envelope that had one', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->prior->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->prior->start, 15000);
    // Fuel is never assigned in the prior period.

    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->prior, $this->selected);

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->selected->start->toDateString(),
        'assigned_minor' => 40000,
    ]);
    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->dining->id,
        'period_start' => $this->selected->start->toDateString(),
        'assigned_minor' => 15000,
    ]);
});

it('leaves an envelope with no prior assignment unassigned after copy', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->prior->start, 40000);

    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->prior, $this->selected);

    $this->assertDatabaseMissing('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->fuel->id,
        'period_start' => $this->selected->start->toDateString(),
    ]);
});

it('never overwrites an existing target assignment when copying into a partially-assigned month', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->prior->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->prior->start, 15000);

    // The target month already has a DIFFERENT Groceries figure the user set.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->selected->start, 99900);

    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->prior, $this->selected);

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->selected->start->toDateString(),
        'assigned_minor' => 99900,
    ]);
    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->dining->id,
        'period_start' => $this->selected->start->toDateString(),
        'assigned_minor' => 15000,
    ]);
});

it('applying copy to an already-empty prior period leaves the selected month empty too', function (): void {
    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->prior, $this->selected);

    expect(DB::table('envelope_assignments')->where('period_start', $this->selected->start->toDateString())->count())->toBe(0);
});
