<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Internal\Support\EnvelopeMoveId;
use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// envelope_moves declares no unique index but its primary key, so the id IS the
// row's identity on both devices: EnvelopeMoveId::for() folds move_group_id,
// kind and period_start into it and every device runs the same arithmetic. A
// row whose stored id disagrees with that fold is reproducible in name only.
const LIFTED_MOVE_GROUP = 'lifted-move-group';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 12:00:00'));

    Currency::query()->updateOrInsert(['code' => 'EUR'], ['minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'liftedid-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 15,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-20 09:00:00']);
    $this->user->refresh();

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'liftedid-g-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'liftedid-d-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $periods = app(PeriodQuery::class);
    $this->genesisKey = $periods->containingForDay(15, CarbonImmutable::parse('2026-06-20 09:00:00'))->start->toDateString();
    $this->strandedKey = $periods->previous($periods->containingForDay(15, CarbonImmutable::parse('2026-06-20 09:00:00')))->start->toDateString();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function runTheGenesisLift(): void
{
    $migration = require base_path('Modules/Budgets/Database/Migrations/2026_08_28_000002_lift_envelope_rows_stranded_below_genesis.php');
    $migration->up();
}

function runTheLiftedMoveIdRepair(): void
{
    $migration = require base_path('Modules/Budgets/Database/Migrations/2026_09_12_000002_give_a_lifted_move_back_the_id_its_own_columns_derive.php');
    $migration->up();
}

// $id is named rather than defaulted because a fixture whose stored id happens
// to equal its own derivation cannot fail the claim these cases make.
function seedMoveRow(int $userId, int $categoryId, int $counterpartId, string $periodStart, string $kind, int $id, ?string $groupId = LIFTED_MOVE_GROUP): void
{
    DB::table('envelope_moves')->insert([
        'id' => $id,
        'user_id' => $userId,
        'category_id' => $categoryId,
        'counterpart_category_id' => $counterpartId,
        'period_start' => $periodStart,
        'amount_minor' => $kind === EnvelopeMoveKind::MoveOut->value ? -5000 : 5000,
        'currency' => 'EUR',
        'kind' => $kind,
        'move_group_id' => $groupId,
        'created_at' => '2026-06-20 09:00:00',
        'updated_at' => '2026-06-20 09:00:00',
    ]);
}

it('gives a lifted move the id its own columns derive, not the one the period it left derived', function (): void {
    $strandedId = EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->strandedKey);
    seedMoveRow($this->user->id, $this->groceries->id, $this->dining->id, $this->strandedKey, EnvelopeMoveKind::MoveOut->value, $strandedId);

    runTheGenesisLift();

    $row = DB::table('envelope_moves')->where('user_id', $this->user->id)->first();

    expect((string) $row->period_start)->toBe($this->genesisKey)
        ->and((int) $row->id)->toBe(EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->genesisKey))
        ->and((int) $row->id)->not->toBe($strandedId)
        ->and((int) $row->amount_minor)->toBe(-5000);
});

// The two halves of one move share a group id, so if kind did not separate them
// the lift would fold both onto a single primary key and lose one of them.
it('lands the two halves of one move on two ids that are not each other', function (): void {
    seedMoveRow(
        $this->user->id, $this->groceries->id, $this->dining->id, $this->strandedKey, EnvelopeMoveKind::MoveOut->value,
        EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->strandedKey),
    );
    seedMoveRow(
        $this->user->id, $this->dining->id, $this->groceries->id, $this->strandedKey, EnvelopeMoveKind::MoveIn->value,
        EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveIn, $this->strandedKey),
    );

    runTheGenesisLift();

    $ids = DB::table('envelope_moves')->where('user_id', $this->user->id)->orderBy('kind')->pluck('id', 'kind');

    expect($ids)->toHaveCount(2)
        ->and((int) $ids[EnvelopeMoveKind::MoveIn->value])->toBe(EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveIn, $this->genesisKey))
        ->and((int) $ids[EnvelopeMoveKind::MoveOut->value])->toBe(EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->genesisKey))
        ->and((int) $ids[EnvelopeMoveKind::MoveIn->value])->not->toBe((int) $ids[EnvelopeMoveKind::MoveOut->value]);
});

// A move written before the id was derived from the move carries whatever the
// table's sequence handed it, and nothing in the tree ever re-derived those.
// Giving one a derived id here would rename a row a peer already holds, and a
// group-less legacy row would fold onto every other group-less row of its kind.
it('leaves a move written before the id was derived on the autoincrement it has always had', function (): void {
    seedMoveRow($this->user->id, $this->groceries->id, $this->dining->id, $this->strandedKey, EnvelopeMoveKind::MoveOut->value, 7, null);
    seedMoveRow($this->user->id, $this->dining->id, $this->groceries->id, $this->strandedKey, EnvelopeMoveKind::MoveIn->value, 9);

    runTheGenesisLift();

    $rows = DB::table('envelope_moves')->where('user_id', $this->user->id)->orderBy('id')->get();

    expect($rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([7, 9])
        ->and($rows->pluck('period_start')->map(fn (mixed $key): string => (string) $key)->all())
        ->toBe([$this->genesisKey, $this->genesisKey]);
});

// The install this repair exists for: the lift already ran, so the row is on
// genesis and its id is still the number the period it left derived.
it('repairs a move an earlier lift already left on the id of the period it came from', function (): void {
    $strandedId = EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->strandedKey);
    seedMoveRow($this->user->id, $this->groceries->id, $this->dining->id, $this->genesisKey, EnvelopeMoveKind::MoveOut->value, $strandedId);

    runTheLiftedMoveIdRepair();

    $row = DB::table('envelope_moves')->where('user_id', $this->user->id)->first();

    expect((int) $row->id)->toBe(EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->genesisKey))
        ->and((string) $row->period_start)->toBe($this->genesisKey)
        ->and((int) $row->amount_minor)->toBe(-5000);
});

it('leaves a genesis row whose id already derives from its own columns where it is', function (): void {
    $derived = EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->genesisKey);
    seedMoveRow($this->user->id, $this->groceries->id, $this->dining->id, $this->genesisKey, EnvelopeMoveKind::MoveOut->value, $derived);
    seedMoveRow($this->user->id, $this->dining->id, $this->groceries->id, $this->genesisKey, EnvelopeMoveKind::MoveIn->value, 11);

    runTheLiftedMoveIdRepair();
    runTheLiftedMoveIdRepair();

    expect(DB::table('envelope_moves')->where('user_id', $this->user->id)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([11, $derived]);
});

// Two rows folding onto one id would be one (move_group_id, kind, period)
// carrying two amounts. The lift keeps both rather than aborting the upgrade or
// picking which amount disappears.
it('keeps a lifted move where it is rather than folding it onto a row already holding its id', function (): void {
    $genesisId = EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->genesisKey);
    $strandedId = EnvelopeMoveId::for(LIFTED_MOVE_GROUP, EnvelopeMoveKind::MoveOut, $this->strandedKey);
    seedMoveRow($this->user->id, $this->groceries->id, $this->dining->id, $this->genesisKey, EnvelopeMoveKind::MoveOut->value, $genesisId);
    seedMoveRow($this->user->id, $this->groceries->id, $this->dining->id, $this->strandedKey, EnvelopeMoveKind::MoveOut->value, $strandedId);

    runTheGenesisLift();

    $rows = DB::table('envelope_moves')->where('user_id', $this->user->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$strandedId, $genesisId])
        ->and($rows->pluck('period_start')->map(fn (mixed $key): string => (string) $key)->all())->toBe([$this->genesisKey, $this->genesisKey]);
});
