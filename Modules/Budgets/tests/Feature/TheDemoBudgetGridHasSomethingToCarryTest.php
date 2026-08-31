<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\PeriodQuery;

beforeEach(function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $this->db = app(DatabaseManager::class);
});

// Every assertion in this file, because the personas differ in the one thing
// this page is anchored on: demo-1 keeps period_start_day 1 and demo-2 keeps
// 25. Asked about demo-1 alone, every assertion below passed while demo-2's
// grid opened on nothing at all -- a calendar-month seeder and a
// period-anchored grid are the same window only for a reader whose period
// starts on the 1st.
dataset('demo personas', ['demo-1', 'demo-2']);

function demoPersona(string $username): User
{
    /** @var User */
    return User::query()->where('username', $username)->firstOrFail();
}

// Genesis and the period on screen were the same month, so the fold walked
// one step: every carry-in read nought and the back control never moved.
it('activates envelope budgeting before the oldest month it assigned', function (string $username): void {
    $user = demoPersona($username);

    $earliest = $this->db->connection()
        ->table('envelope_assignments')
        ->where('user_id', $user->id)
        ->min('period_start');

    $activated = $this->db->connection()
        ->table('users')
        ->where('id', $user->id)
        ->value('envelope_activated_at');

    expect($activated)->not->toBeNull();
    expect(substr((string) $activated, 0, 10))->toBe((string) $earliest);
})->with('demo personas');

it('assigns across three periods, so two of them can carry forward', function (string $username): void {
    $periods = $this->db->connection()
        ->table('envelope_assignments')
        ->where('user_id', demoPersona($username)->id)
        ->distinct()
        ->pluck('period_start')
        ->all();

    expect($periods)->toHaveCount(3);
})->with('demo personas');

it('carries a non-zero balance into the period on screen', function (string $username): void {
    $user = demoPersona($username);

    // The grid is always rendered for the reader looking at it: base currency
    // is reader-scoped by design and refuses to guess one off-console.
    $this->actingAs($user);

    $fold = app(CarryoverQuery::class)->forUserAndPeriod(
        $user,
        app(PeriodQuery::class)->containingForUser($user, now()->toImmutable()),
    );

    $carried = array_filter(
        $fold['rows'],
        static fn (EnvelopeRow $row): bool => $row->carriedInMinor !== 0,
    );

    expect($carried)->not->toBeEmpty();
})->with('demo personas');

/**
 * The deficit a period ends on, counted by what the envelope carrying it is set
 * to do with it: rolled forward under carry_negative, absorbed by the pool
 * under reduce_to_budget. Every term of the availability the grid prints is
 * checked on the way past.
 *
 * @return array{rolled: int, absorbed: int}
 */
function demoDeficitsByMode(User $user): array
{
    $periods = app(PeriodQuery::class);
    $current = $periods->containingForUser($user, now()->toImmutable());
    $second = $periods->previous($current);
    $first = $periods->previous($second);

    $folds = [];
    foreach ([$first, $second, $current] as $period) {
        $folds[] = app(CarryoverQuery::class)->forUserAndPeriod($user, $period);
    }

    foreach ($folds as $fold) {
        foreach ($fold['rows'] as $row) {
            expect($row->availableMinor)->toBe(
                $row->assignedMinor + $row->carriedInMinor + $row->netMovedMinor - $row->spentMinor,
            );
        }
    }

    $rolled = 0;
    $absorbed = 0;

    for ($step = 1; $step < count($folds); $step++) {
        foreach ($folds[$step]['rows'] as $categoryId => $row) {
            $previous = $folds[$step - 1]['rows'][$categoryId];
            $absorbs = $previous->availableMinor < 0 && $previous->overspendMode->absorbsShortfallIntoPool();

            expect($row->carriedInMinor)->toBe($absorbs ? 0 : $previous->availableMinor);

            if ($previous->availableMinor < 0) {
                $absorbs ? $absorbed++ : $rolled++;
            }
        }
    }

    return ['rolled' => $rolled, 'absorbed' => $absorbed];
}

// Every term of the availability the grid prints, over the whole seeded span,
// for both personas: the carry rule is the same arithmetic whatever day the
// reader's period opens on, and it is the half that holds for both.
it('folds three seeded months the way each envelope is set to', function (string $username): void {
    $user = demoPersona($username);
    $this->actingAs($user);

    $deficits = demoDeficitsByMode($user);

    // A window with no deficit at all exercises neither branch of the rule
    // above, so the walk would pass by asserting nothing.
    expect($deficits['rolled'] + $deficits['absorbed'])->toBeGreaterThan(0);
})->with('demo personas');

// Not widened, and carried as a debt rather than scoped down quietly. The two
// modes differ only on an envelope that ends a period below zero, and demo-2's
// ledger reaches exactly one such envelope: measured on the seeded window,
// eating-out, insurance-health and transport-public -- three of the four
// categories DemoEnvelopeBudgetsSeeder gives a mode to -- all spend 0 for that
// persona, and only subscriptions-music (unassigned, so on the default mode)
// ever goes negative. One envelope cannot demonstrate two modes. Giving demo-2
// a second is a change to the Ledger demo transaction set, not to this module's
// constants; when that lands, drop the guard below and put the persona back on
// the dataset above.
it('exercises both overspend modes at a deficit, for the persona whose dataset reaches both', function (): void {
    $user = demoPersona('demo-1');
    $this->actingAs($user);

    $deficits = demoDeficitsByMode($user);

    expect($deficits['rolled'])->toBeGreaterThan(0)
        ->and($deficits['absorbed'])->toBeGreaterThan(0);

    $thin = demoPersona('demo-2');
    $this->actingAs($thin);

    // The debt, stated as an assertion: it goes red the day demo-2's ledger
    // reaches a second overspendable envelope, which is when this test and its
    // reason should be deleted and the dataset above widened to both.
    expect(demoDeficitsByMode($thin)['rolled'])->toBe(
        0,
        'demo-2 now rolls a deficit forward — widen the fold test to both personas and delete this one',
    );
});

// The two modes differ only where a period ends below zero, so a dataset with
// one mode cannot show that the choice does anything.
it('sets both overspend modes on the demo categories', function (string $username): void {
    $modes = $this->db->connection()
        ->table('envelope_settings')
        ->where('user_id', demoPersona($username)->id)
        ->pluck('overspend_mode')
        ->unique()
        ->values()
        ->all();

    expect($modes)->toContain(OverspendMode::CarryNegative->value)
        ->and($modes)->toContain(OverspendMode::ReduceToBudget->value);
})->with('demo personas');

it('seeds every envelope move as a balanced pair sharing one group id', function (string $username): void {
    $groups = $this->db->connection()
        ->table('envelope_moves')
        ->where('user_id', demoPersona($username)->id)
        ->groupBy('move_group_id')
        ->selectRaw('move_group_id, COUNT(*) AS legs, SUM(amount_minor) AS net')
        ->get();

    expect($groups)->not->toBeEmpty();

    foreach ($groups as $group) {
        expect((int) $group->legs)->toBe(2);
        expect((int) $group->net)->toBe(0);
    }
})->with('demo personas');

it('gives every seeded move a memo in the interface language', function (string $username): void {
    $memos = $this->db->connection()
        ->table('envelope_moves')
        ->where('user_id', demoPersona($username)->id)
        ->pluck('memo')
        ->all();

    expect($memos)->not->toBeEmpty();
    expect(array_filter($memos, static fn (mixed $memo): bool => $memo === null || $memo === ''))->toBeEmpty();
})->with('demo personas');

// The control was not broken -- it clamps at genesis, and genesis was the
// month already on screen, so the first press did nothing and read as dead.
it('walks back two periods before the grid clamps at genesis', function (string $username): void {
    $component = Livewire::actingAs(demoPersona($username))->test(BudgetsPage::class);

    $seen = [(string) $component->get('periodStartStr')];
    for ($press = 0; $press < 4; $press++) {
        $component->call('prevPeriod');
        $seen[] = (string) $component->get('periodStartStr');
    }

    // Three distinct months out of five readings: two presses move, the rest
    // clamp. It used to be one distinct month out of five.
    expect(array_unique($seen))->toHaveCount(3);
    expect($seen[2])->toBe($seen[4]);
})->with('demo personas');

// The page opened on EUR 2,490.00 assigned against EUR 0.00 spent for the
// persona whose period starts on the 25th: a third of that reader's spend sat
// in a period the back control refuses to reach, because the ledger seeder
// anchored on startOfMonth() and the grid on period_start_day.
it('opens on a period that holds the spend the demo made', function (string $username): void {
    $user = demoPersona($username);
    $this->actingAs($user);

    $fold = app(CarryoverQuery::class)->forUserAndPeriod(
        $user,
        app(PeriodQuery::class)->containingForUser($user, now()->toImmutable()),
    );

    $spent = 0;
    foreach ($fold['rows'] as $row) {
        $spent += $row->spentMinor;
    }

    expect($spent)->toBeGreaterThan(
        0,
        $username.' opens its budgets grid on nothing spent, so every figure the page exists to show is blank',
    );
})->with('demo personas');

it('leaves no seeded row outside the periods the grid can navigate to', function (string $username): void {
    $user = demoPersona($username);

    $periods = app(PeriodQuery::class);
    $current = $periods->containingForUser($user, now()->toImmutable());
    $earliest = $periods->previous($periods->previous($current));

    $stray = $this->db->connection()
        ->table('transactions')
        ->where('user_id', $user->id)
        ->where('source_format', 'demo')
        ->where(function ($query) use ($earliest, $current): void {
            $query->where('posted_at', '<', $earliest->start->toDateString())
                ->orWhere('posted_at', '>=', $current->endExclusive->toDateString());
        })
        ->selectRaw('COUNT(*) AS rows_outside, COALESCE(SUM(amount_minor), 0) AS minor_outside')
        ->first();

    expect((int) ($stray->rows_outside ?? 0))->toBe(
        0,
        $username.' has demo rows outside '.$earliest->start->toDateString().' … '
            .$current->endExclusive->subDay()->toDateString()
            .', worth '.(int) ($stray->minor_outside ?? 0).' minor, in a period prevPeriod() will not open',
    );
})->with('demo personas');
