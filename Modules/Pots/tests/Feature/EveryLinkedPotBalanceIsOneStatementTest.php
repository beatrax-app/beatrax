<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Internal\Services\PotRowLoader;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Services\PotBalanceQuery;

// A pot has no balance column, so every card on the goals page and the pots
// page is a SUM. Asking for them one card at a time made the page's cost the
// reader's pot count, which is the shape movementCounts() beside it refuses.

$elpUser = static function (string $username): User {
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
};

$elpAccount = static function (DatabaseManager $db, User $user): int {
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'ELP ASN',
        'slug' => 'elp-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ELP'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
};

// One goal, one pot linked to it, and $movements deposits of 100 each.
$elpLinkedPot = static function (DatabaseManager $db, User $user, int $accountId, int $n, int $movements): int {
    $goalId = $db->connection()->table('goals')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Goal '.$n,
        'target_minor' => 500000,
        'target_currency' => Currency::Eur->value,
        'start_date' => '2026-01-01',
        'target_date' => '2027-01-01',
        'status' => 'active',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $potId = $db->connection()->table('pots')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'goal_id' => $goalId,
        'name' => 'Pot '.$n,
        'currency' => Currency::Eur->value,
        'status' => PotStatus::Active->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $rows = [];
    for ($i = 0; $i < $movements; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'pot_id' => $potId,
            'amount_minor' => 100,
            'currency' => Currency::Eur->value,
            'kind' => 'deposit',
            'created_at' => '2026-02-01 00:00:00',
            'updated_at' => '2026-02-01 00:00:00',
        ];
    }

    if ($rows !== []) {
        $db->connection()->table('pot_movements')->insert($rows);
    }

    return $potId;
};

$elpStatements = static function (callable $run): int {
    $statements = 0;
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements++;
    });

    $run();

    return $statements;
};

it('costs three statements for one linked pot and three for twelve', function () use ($elpUser, $elpAccount, $elpLinkedPot, $elpStatements): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var PotBalanceQuery $balances */
    $balances = app(PotBalanceQuery::class);

    $one = $elpUser('elp-one');
    $elpLinkedPot($db, $one, $elpAccount($db, $one), 1, 3);

    $many = $elpUser('elp-many');
    $manyAccount = $elpAccount($db, $many);
    foreach (range(1, 12) as $n) {
        $elpLinkedPot($db, $many, $manyAccount, $n, 3);
    }

    $oneCost = $elpStatements(static fn () => $balances->linkedPotBalancesForUser($one));
    $manyCost = $elpStatements(static fn () => $balances->linkedPotBalancesForUser($many));

    expect($oneCost)->toBe(3)
        ->and($manyCost)->toBe(3);
});

it('answers each pot the balance its own per-pot sum answers', function () use ($elpUser, $elpAccount, $elpLinkedPot): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var PotRowLoader $loader */
    $loader = app(PotRowLoader::class);

    $user = $elpUser('elp-parity');
    $accountId = $elpAccount($db, $user);

    $potIds = [];
    foreach ([0, 1, 5] as $index => $movements) {
        $potIds[] = $elpLinkedPot($db, $user, $accountId, $index, $movements);
    }

    // A movement in a currency the pot is not denominated in: balanceForPot()
    // leaves it out, and the batched sum has to leave out the same one.
    $db->connection()->table('pot_movements')->insert([
        'user_id' => $user->id,
        'pot_id' => $potIds[2],
        'amount_minor' => 999999,
        'currency' => Currency::Usd->value,
        'kind' => 'deposit',
        'created_at' => '2026-02-02 00:00:00',
        'updated_at' => '2026-02-02 00:00:00',
    ]);

    $batched = $loader->balancesForPots($potIds, $user);

    expect($batched[$potIds[0]] ?? 0)->toBe($loader->balanceForPot($potIds[0], $user))
        ->and($batched[$potIds[0]] ?? 0)->toBe(0)
        ->and($batched[$potIds[1]] ?? 0)->toBe($loader->balanceForPot($potIds[1], $user))
        ->and($batched[$potIds[1]] ?? 0)->toBe(100)
        ->and($batched[$potIds[2]] ?? 0)->toBe($loader->balanceForPot($potIds[2], $user))
        ->and($batched[$potIds[2]] ?? 0)->toBe(500);
});
