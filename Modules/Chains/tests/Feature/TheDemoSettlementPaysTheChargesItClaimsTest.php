<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Support\SettlementTolerance;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// The demo settled the card with a flat 225.00 whatever the card had been
// used for, and told /chains the unaccounted difference was zero. It was
// 97.72, 605.95 and 649.28 — the resolver confirms a link only inside EUR 5
// or 2%, so all three would really have been exceeded-tolerance candidates.
//
// The settlement is now the statement: it carries what the period charged.

/** @return array{settlement: Transaction, charged: int, count: int} */
function demoSettlementCoverage(Transaction $settlement, User $user, ?string $periodStart): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $cardId = $db->connection()->table('accounts')->where('slug', 'ics-demo-1')->value('id');

    $charges = Transaction::query()
        ->where('user_id', $user->id)
        ->where('account_id', $cardId)
        ->where('type', 'expense')
        ->where('posted_at', '<=', $settlement->posted_at)
        ->when($periodStart !== null, static fn ($q) => $q->where('posted_at', '>', $periodStart))
        ->get(['settled_amount_minor']);

    return [
        'settlement' => $settlement,
        'charged' => (int) $charges->sum(static fn (Transaction $c): int => abs($c->settled_amount_minor)),
        'count' => $charges->count(),
    ];
}

/** @return list<Transaction> */
function demoSettlements(User $user): array
{
    return Transaction::query()
        ->where('user_id', $user->id)
        ->where('source_format', 'demo')
        ->where('description', 'ICS afrekening MasterCard')
        ->orderBy('posted_at')
        ->get()
        ->all();
}

it('pays each settlement the amount its own period charged', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    $settlements = demoSettlements($user);
    expect($settlements)->not->toBeEmpty();

    $periodStart = null;
    $offenders = [];

    foreach ($settlements as $settlement) {
        $coverage = demoSettlementCoverage($settlement, $user, $periodStart);
        $periodStart = $settlement->posted_at->toDateString();

        if ($coverage['count'] === 0) {
            continue;
        }

        $delta = $coverage['charged'] - abs($settlement->settled_amount_minor);
        $tolerance = SettlementTolerance::minorFor($settlement->settled_amount_minor);

        if (abs($delta) > $tolerance) {
            $offenders[] = $settlement->posted_at->toDateString()
                .' — charged '.$coverage['charged']
                .', settled '.abs($settlement->settled_amount_minor)
                .', adrift by '.$delta.' against a tolerance of '.$tolerance;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A demo settlement does not pay what its period charged:',
        ...$offenders,
    ]));
});

it('states an unaccounted difference the ledger agrees with', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    $links = ChainLink::query()
        ->where('user_id', $user->id)
        ->where('kind', 'ics_bulk_settle')
        ->get();

    expect($links)->not->toBeEmpty();

    $periodStart = null;
    $claimedByDate = [];

    foreach (demoSettlements($user) as $settlement) {
        $coverage = demoSettlementCoverage($settlement, $user, $periodStart);
        $periodStart = $settlement->posted_at->toDateString();
        $claimedByDate[$settlement->id] = $coverage['charged'] - abs($settlement->settled_amount_minor);
    }

    $offenders = [];

    foreach ($links as $link) {
        $evidence = $link->evidence;
        $stated = is_array($evidence) ? ($evidence['unaccounted_delta_minor'] ?? null) : null;
        $real = $claimedByDate[$link->from_transaction_id] ?? $claimedByDate[$link->to_transaction_id] ?? null;

        if ($real === null || ! is_numeric($stated)) {
            continue;
        }

        if ((int) $stated !== $real) {
            $offenders[] = 'link '.$link->id.' states '.$stated.' but the ledger says '.$real;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A seeded chain link states a difference the ledger does not hold:',
        ...$offenders,
    ]));
});

it('keeps every seeded bulk-settle link confirmed, because each one is inside tolerance', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    $states = ChainLink::query()
        ->where('user_id', $user->id)
        ->where('kind', 'ics_bulk_settle')
        ->pluck('state')
        ->unique()
        ->values()
        ->all();

    expect($states)->toBe([ChainLinkState::Confirmed->value]);
});

it('leaves both legs of every settlement transfer summing to nothing', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    $legs = Transaction::query()
        ->where('user_id', $user->id)
        ->where('source_format', 'demo')
        ->whereIn('description', ['ICS afrekening MasterCard', 'Afrekening MasterCard ICS'])
        ->get(['posted_at', 'settled_amount_minor']);

    expect($legs)->not->toBeEmpty();

    $byDate = [];
    foreach ($legs as $leg) {
        $byDate[$leg->posted_at->toDateString()] ??= 0;
        $byDate[$leg->posted_at->toDateString()] += $leg->settled_amount_minor;
    }

    expect(array_values(array_unique(array_values($byDate))))->toBe([0]);
});
