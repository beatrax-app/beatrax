<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Internal\Support\EnvelopeMoveId;
use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Budgets\Public\Services\EnvelopePeriodRekeyer;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// `envelope_moves.kind` is a bare string(32) with no CHECK trigger and it is in
// the sync registry's create-required set, so a peer on a newer build lands a
// spelling this build has no case for. The rekey read it back with
// EnvelopeMoveKind::from(), and one such row raised a ValueError that rolled
// back the re-filing of EVERY envelope row and 500'd the settings save.

const UNNAMEABLE_KIND = 'move_sideways';

const PEER_MOVE_GROUP = 'a2f0c9de-6b1e-4d33-9c77-0e5a1f8b4d21';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 12:00:00'));

    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'unnameable-rekey-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'unnameable-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'unnameable-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Written straight into the table on purpose: this is what the applier does
// with a create-row op, which validates ownership and completeness and then
// writes the peer's fields verbatim. The pk travels with the op, so the row
// arrives carrying the id the newer build derived rather than an autoincrement.
function landAMoveFromANewerBuild(User $user, Category $to, Category $from, string $periodStart): int
{
    $id = 9_001;

    DB::table('envelope_moves')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'category_id' => $to->id,
        'counterpart_category_id' => $from->id,
        'period_start' => $periodStart,
        'amount_minor' => 2_500,
        'currency' => 'EUR',
        'kind' => UNNAMEABLE_KIND,
        'memo' => 'from a newer build',
        'move_group_id' => PEER_MOVE_GROUP,
        'created_at' => '2026-06-18 09:00:00',
        'updated_at' => '2026-06-18 09:00:00',
    ]);

    return $id;
}

function movePeriodStartDayToAndRekey(User $user, int $newDay, int $oldDay): void
{
    DB::table('users')->where('id', $user->id)->update(['period_start_day' => $newDay]);
    $user->refresh();

    app(EnvelopePeriodRekeyer::class)->rekeyToCurrentPeriods($oldDay);
}

it('re-files every move when one of them carries a kind this build has no case for', function (): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $before->start, 40_000);
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $before->start, 5_000, null);
    landAMoveFromANewerBuild($this->user, $this->groceries, $this->dining, $before->start->toDateString());

    movePeriodStartDayToAndRekey($this->user, 15, 1);

    $target = app(PeriodQuery::class)->current()->start->toDateString();

    $moves = DB::table('envelope_moves')->where('user_id', $this->user->id)->get();
    expect($moves)->toHaveCount(3);
    foreach ($moves as $move) {
        expect($move->period_start)->toBe($target, 'a move was left keyed to a period the fold no longer walks');
    }

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('period_start'))->toBe($target);
});

it('keeps the spelling it cannot name rather than folding it into a kind it can', function (): void {
    $before = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $before->start, 5_000, null);
    landAMoveFromANewerBuild($this->user, $this->groceries, $this->dining, $before->start->toDateString());

    movePeriodStartDayToAndRekey($this->user, 15, 1);

    $peerRow = DB::table('envelope_moves')->where('move_group_id', PEER_MOVE_GROUP)->first();

    expect($peerRow)->not->toBeNull()
        ->and($peerRow->kind)->toBe(UNNAMEABLE_KIND)
        ->and((int) $peerRow->amount_minor)->toBe(2_500)
        ->and($peerRow->memo)->toBe('from a newer build');
});

// The id is the whole reason the enum cannot be consulted here: the peer that
// knows the spelling derives from its own case's `value`, this build derives
// from the column, and the two are the same string or the devices disagree
// about which row is which on a table with no unique index to arbitrate.
it('derives the re-filed id from the stored spelling, which is what the peer derived from too', function (): void {
    $before = app(PeriodQuery::class)->current();
    landAMoveFromANewerBuild($this->user, $this->groceries, $this->dining, $before->start->toDateString());

    movePeriodStartDayToAndRekey($this->user, 15, 1);

    $target = app(PeriodQuery::class)->current()->start->toDateString();

    expect(DB::table('envelope_moves')->where('move_group_id', PEER_MOVE_GROUP)->value('id'))
        ->toBe(EnvelopeMoveId::for(PEER_MOVE_GROUP, UNNAMEABLE_KIND, $target));
});

it('gives a known kind the same id whether it arrives as the case or as the stored string', function (): void {
    foreach ([EnvelopeMoveKind::MoveOut, EnvelopeMoveKind::MoveIn] as $kind) {
        expect(EnvelopeMoveId::for(PEER_MOVE_GROUP, $kind, '2026-06-15'))
            ->toBe(EnvelopeMoveId::for(PEER_MOVE_GROUP, $kind->value, '2026-06-15'));
    }
});
