<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->user = User::create([
        'username' => 'copy-month-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->categoryIds = [];
    foreach (['Groceries', 'Dining', 'Transport'] as $index => $name) {
        $this->categoryIds[] = DB::table('categories')->insertGetId([
            'user_id' => $this->user->id,
            'name' => $name,
            'slug' => strtolower($name).'-copy-month',
            'kind' => 'expense',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('envelope_assignments')->insert([
            'user_id' => $this->user->id,
            'category_id' => $this->categoryIds[$index],
            'period_start' => '2026-04-01',
            'assigned_minor' => 10000 + $index,
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function cltPeriod(string $start, string $endExclusive): Period
{
    return new Period(CarbonImmutable::parse($start), CarbonImmutable::parse($endExclusive), $start);
}

it('copies a whole month or none of it', function (): void {
    // A half-way failure, forced by making the last row's category one the
    // per-row authorization check refuses.
    $writer = app(EnvelopeWriter::class);

    DB::table('categories')->where('id', $this->categoryIds[2])->update(['kind' => 'income']);

    try {
        $writer->copyFromPeriod($this->user, cltPeriod('2026-04-01', '2026-05-01'), cltPeriod('2026-05-01', '2026-06-01'));
    } catch (InvalidArgumentException) {
        // The refusal is the point; what matters is what it left behind.
    }

    expect(DB::table('envelope_assignments')->where('period_start', '2026-05-01')->count())->toBe(0);
});

it('dispatches one event per copied row, after the copy has committed', function (): void {
    Event::fake([EnvelopeAssignmentMutated::class]);

    app(EnvelopeWriter::class)->copyFromPeriod(
        $this->user,
        cltPeriod('2026-04-01', '2026-05-01'),
        cltPeriod('2026-05-01', '2026-06-01'),
    );

    Event::assertDispatchedTimes(EnvelopeAssignmentMutated::class, 3);
    expect(DB::table('envelope_assignments')->where('period_start', '2026-05-01')->count())->toBe(3);
});
