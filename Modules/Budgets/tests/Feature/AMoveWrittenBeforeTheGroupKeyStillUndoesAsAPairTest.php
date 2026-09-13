<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// move_group_id was added after envelope_moves shipped, so undoMove() still has
// to pair a row written without one — on counterpart, period, opposite amount
// and a created_at good only to the second. Nothing had ever run that arm.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'legacyundo-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(2)->startOfMonth(),
    ]);

    $this->from = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'legacyundo-from-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->to = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'legacyundo-to-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
    $this->writer = app(EnvelopeWriter::class);
});

it('takes both halves of a move that predates the group key', function (): void {
    $debitId = $this->writer->move($this->user, $this->from->id, $this->to->id, $this->period->start, 2000, 'before the key');

    DB::table('envelope_moves')->where('user_id', $this->user->id)->update(['move_group_id' => null]);

    $this->writer->undoMove($this->user, $debitId);

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('leaves another reader’s move where it is, and says nothing about it', function (): void {
    $owner = $this->user;
    $stranger = User::create([
        'username' => 'legacyundo-stranger-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $debitId = $this->writer->move($owner, $this->from->id, $this->to->id, $this->period->start, 2000);

    $this->writer->undoMove($stranger, $debitId);

    // Read past UserScope: a scoped query answers "no such row" whether or not
    // the row survived, which is the one thing this has to tell apart.
    expect(DB::table('envelope_moves')->where('user_id', $owner->id)->count())->toBe(2);
});

it('returns quietly for a move id that is not there at all', function (): void {
    $this->writer->undoMove($this->user, 999_999);

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->count())->toBe(0);
});
