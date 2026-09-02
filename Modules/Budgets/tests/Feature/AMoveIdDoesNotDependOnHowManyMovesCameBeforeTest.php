<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Internal\Support\EnvelopeMoveId;
use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// Two devices that were apart both took the next autoincrement for a move of
// their own, so both moves were id 9 and the pair never converged. The table
// declares no unique index but its primary key, so nothing downstream could
// tell the two rows apart either.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'moveid-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $suffix = bin2hex(random_bytes(3));
    $this->from = Category::create(['user_id' => null, 'name' => 'From', 'slug' => 'mid-from-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->to = Category::create(['user_id' => null, 'name' => 'To', 'slug' => 'mid-to-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->from->id, $this->period->start, 500000);
});

it('gives a move the id its own identity derives, not the next number in the table', function (): void {
    app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 1000);

    $row = DB::table('envelope_moves')->where('user_id', $this->user->id)->orderBy('id')->first();
    $groupId = (string) $row->move_group_id;

    $derived = [
        EnvelopeMoveId::for($groupId, EnvelopeMoveKind::MoveOut, $this->period->start->toDateString()),
        EnvelopeMoveId::for($groupId, EnvelopeMoveKind::MoveIn, $this->period->start->toDateString()),
    ];

    $stored = DB::table('envelope_moves')->where('move_group_id', $groupId)->pluck('id')->all();

    sort($derived);
    sort($stored);

    expect($stored)->toBe($derived);
});

// The property that kills the collision: the id is a function of the move, not
// of how full the table was when it was written. Two devices holding different
// numbers of moves cannot land on one id for two different moves.
it('gives the same move the same id whatever the table already holds', function (): void {
    $first = app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 1000);

    $groupId = (string) DB::table('envelope_moves')->where('id', $first)->value('move_group_id');
    $period = $this->period->start->toDateString();

    for ($i = 0; $i < 6; $i++) {
        app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 100);
    }

    expect(EnvelopeMoveId::for($groupId, EnvelopeMoveKind::MoveOut, $period))->toBe($first);
});

it('gives two different moves two different ids', function (): void {
    $writer = app(EnvelopeWriter::class);

    $a = $writer->move($this->user, $this->from->id, $this->to->id, $this->period->start, 777);
    $b = $writer->move($this->user, $this->from->id, $this->to->id, $this->period->start, 888);

    expect($a)->not->toBe($b)
        ->and(DB::table('envelope_moves')->where('user_id', $this->user->id)->distinct()->count('id'))->toBe(4);
});

// The two halves of one move share a group id and must not share an id.
it('keeps the two rows of one move apart', function (): void {
    $id = app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 1000);

    $groupId = (string) DB::table('envelope_moves')->where('id', $id)->value('move_group_id');
    $period = $this->period->start->toDateString();

    expect(EnvelopeMoveId::for($groupId, EnvelopeMoveKind::MoveOut, $period))
        ->not->toBe(EnvelopeMoveId::for($groupId, EnvelopeMoveKind::MoveIn, $period));
});

it('undoes a move whose id crossed the browser as a string', function (): void {
    $id = app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 1000);

    app(EnvelopeWriter::class)->undoMove($this->user, DerivedRowId::fromWire((string) $id));

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->count())->toBe(0);
});
