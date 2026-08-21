<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Models\EnvelopeAssignment;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'assignment-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'assign-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    $this->periodA = app(PeriodQuery::class)->current();
    $this->periodB = app(PeriodQuery::class)->next($this->periodA);
});

it('stores a different assigned amount for the same category in two different months', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 20000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodB->start, 30000);

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->periodA->start->toDateString(),
        'assigned_minor' => 20000,
    ]);
    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->periodB->start->toDateString(),
        'assigned_minor' => 30000,
    ]);
    expect(EnvelopeAssignment::query()->where('category_id', $this->groceries->id)->count())->toBe(2);
});

it('upserts rather than duplicating when the same (category, period) is set twice, preserving the row id', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 20000);
    $firstId = EnvelopeAssignment::query()->where('category_id', $this->groceries->id)->sole()->id;

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 25000);
    $secondId = EnvelopeAssignment::query()->where('category_id', $this->groceries->id)->sole()->id;

    // Editing must never delete-and-reinsert: per-(table, pk, field) LWW sync
    // convergence depends on the primary key surviving.
    expect($secondId)->toBe($firstId);
    $this->assertDatabaseHas('envelope_assignments', ['id' => $firstId, 'assigned_minor' => 25000]);
});

it('tombstones the row when assigned is set back to zero (absence == 0), not a stored zero row', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 20000);
    $this->assertDatabaseHas('envelope_assignments', ['category_id' => $this->groceries->id]);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 0);

    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $this->groceries->id]);
});

it('rejects a category the user does not own and that is not global (IDOR)', function (): void {
    $mallory = User::create(['username' => 'mallory-assign', 'password' => 'x', 'period_start_day' => 1]);
    $foreign = Category::create(['user_id' => $mallory->id, 'name' => 'Therapy', 'slug' => 'assign-therapy-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    expect(fn () => app(EnvelopeWriter::class)->setAssigned($this->user, $foreign->id, $this->periodA->start, 20000))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $foreign->id]);
});

it('stores period_start as a bare Y-m-d (no 00:00:00 trap) even when written through the model', function (): void {
    // Writing through the Eloquent model (a factory, a future call site) must
    // agree with EnvelopeWriter's raw storage format, or the fold's exact
    // string match silently zeroes the envelope.
    $assignment = EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->periodA->start->toDateString(),
        'assigned_minor' => 20000,
        'currency' => 'EUR',
    ]);

    $raw = DB::table('envelope_assignments')
        ->where('id', $assignment->id)
        ->value('period_start');
    expect($raw)->toBe($this->periodA->start->toDateString());

    $this->assertDatabaseHas('envelope_assignments', [
        'id' => $assignment->id,
        'period_start' => $this->periodA->start->toDateString(),
    ]);

    expect($assignment->fresh()?->period_start)->toBeInstanceOf(CarbonImmutable::class);
});

it('never treats category_budgets as an authoritative write target for envelope assignment', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 20000);

    $this->assertDatabaseMissing('category_budgets', ['category_id' => $this->groceries->id]);
});

it('stores period_start written as a CarbonImmutable instance as a bare Y-m-d string', function (): void {
    $assignment = EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->periodA->start,
        'assigned_minor' => 15000,
        'currency' => 'EUR',
    ]);

    $raw = DB::table('envelope_assignments')->where('id', $assignment->id)->value('period_start');
    expect($raw)->toBe($this->periodA->start->toDateString());
});
