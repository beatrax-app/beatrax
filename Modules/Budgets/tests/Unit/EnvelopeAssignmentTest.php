<?php

declare(strict_types=1);

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

// The fold matches period_start with an exact where('period_start', 'Y-m-d')
// string, so a stored "Y-m-d 00:00:00" silently zeroes the envelope. The guard
// belongs on the write path the app actually uses, not on a model accessor no
// production caller reaches.
it('stores period_start as a bare Y-m-d, with no 00:00:00 suffix for the fold to miss', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 20000);

    $raw = DB::table('envelope_assignments')
        ->where('category_id', $this->groceries->id)
        ->value('period_start');

    expect($raw)->toBe($this->periodA->start->toDateString());
});

it('edits an existing row without restating the period, so the format cannot drift on the second write', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 20000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->periodA->start, 15000);

    $raw = DB::table('envelope_assignments')
        ->where('category_id', $this->groceries->id)
        ->value('period_start');

    expect($raw)->toBe($this->periodA->start->toDateString());
});
