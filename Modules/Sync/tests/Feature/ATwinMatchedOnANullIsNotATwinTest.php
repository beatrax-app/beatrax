<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\PeerRowAliases;

uses(RefreshDatabase::class);

// An alias says "the row you call 9999 is the row I call 4". Every later op the
// peer sends for 9999 is applied to 4, so a wrong alias does not fail: it moves
// the peer's money into a row of this device's choosing, quietly and for good.
function aliasUser(): User
{
    return User::query()->create([
        'username' => 'alias-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function aliasAccount(int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return (int) DB::table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN alias', 'slug' => 'alias-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function aliasGoal(int $userId): int
{
    return (int) DB::table('goals')->insertGetId([
        'user_id' => $userId, 'name' => 'Japan '.bin2hex(random_bytes(3)),
        'target_minor' => 500000, 'target_currency' => 'EUR',
        'start_date' => '2026-07-01', 'target_date' => '2027-07-01', 'status' => 'active',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function aliasPot(int $userId, int $accountId, ?int $goalId, string $status = 'active'): int
{
    return (int) DB::table('pots')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'name' => 'Pot '.bin2hex(random_bytes(3)),
        'currency' => 'EUR', 'status' => $status, 'goal_id' => $goalId,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

it('does not call an arbitrary row the twin of a payload holding a null', function (): void {
    $user = aliasUser();
    $accountId = aliasAccount((int) $user->id);
    aliasPot((int) $user->id, $accountId, null);
    aliasPot((int) $user->id, $accountId, null);

    $aliases = app(PeerRowAliases::class);

    $aliases->remember('pots', 'peer-device', 9999, [
        'user_id' => $user->id, 'account_id' => $accountId, 'name' => 'Holiday',
        'currency' => 'EUR', 'status' => 'active', 'goal_id' => null,
    ], (int) $user->id);

    expect($aliases->localFor('pots', 'peer-device', 9999, (int) $user->id))->toBeNull();
});

it('does not read a twin off an index that does not cover the row it found', function (): void {
    $user = aliasUser();
    $accountId = aliasAccount((int) $user->id);

    // pots_active_goal_unique covers active pots only, so this archived one is
    // outside it -- and the predicate is what the reader cannot see.
    $goalId = aliasGoal((int) $user->id);
    $archived = aliasPot((int) $user->id, $accountId, $goalId, 'archived');

    $aliases = app(PeerRowAliases::class);

    $aliases->remember('pots', 'peer-device', 8888, [
        'user_id' => $user->id, 'account_id' => $accountId, 'name' => 'Japan',
        'currency' => 'EUR', 'status' => 'active', 'goal_id' => $goalId,
    ], (int) $user->id);

    expect($aliases->localFor('pots', 'peer-device', 8888, (int) $user->id))->toBeNull()
        ->and($archived)->toBeGreaterThan(0);
});

it('still recognises a twin by an index whose every column carries a value', function (): void {
    $user = aliasUser();
    $slug = 'boodschappen-'.bin2hex(random_bytes(3));

    $localId = (int) DB::table('categories')->insertGetId([
        'user_id' => $user->id, 'name' => 'Boodschappen', 'slug' => $slug,
        'kind' => 'expense', 'display_order' => 0, 'name_is_default' => 0,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    $aliases = app(PeerRowAliases::class);

    $aliases->remember('categories', 'peer-device', 7777, [
        'user_id' => $user->id, 'name' => 'Boodschappen', 'slug' => $slug, 'kind' => 'expense',
    ], (int) $user->id);

    // The whole point of the alias table, and the thing the two refusals above
    // must not have taken away with them.
    expect($aliases->localFor('categories', 'peer-device', 7777, (int) $user->id))
        ->toBe((string) $localId);
});

it('records nothing when the payload never names the index columns', function (): void {
    $user = aliasUser();
    $slug = 'vervoer-'.bin2hex(random_bytes(3));

    DB::table('categories')->insert([
        'user_id' => $user->id, 'name' => 'Vervoer', 'slug' => $slug,
        'kind' => 'expense', 'display_order' => 0, 'name_is_default' => 0,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    $aliases = app(PeerRowAliases::class);

    $aliases->remember('categories', 'peer-device', 6666, ['name' => 'Vervoer'], (int) $user->id);

    expect($aliases->localFor('categories', 'peer-device', 6666, (int) $user->id))->toBeNull();
});
