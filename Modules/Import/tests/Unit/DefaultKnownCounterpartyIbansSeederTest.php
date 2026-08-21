<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Models\KnownCounterpartyIban;

uses(RefreshDatabase::class);

function makeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

it('seeds two aliases for a fresh user', function (): void {
    $user = makeUser('seeder-fresh');

    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    $rows = KnownCounterpartyIban::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(2);

    $paypal = $rows->firstWhere('real_iban', 'LU89751000135104200E');
    expect($paypal)->not->toBeNull();
    expect($paypal->target_account_kind)->toBe('paypal');

    $ics = $rows->firstWhere('real_iban', 'NL08ABNA0526650664');
    expect($ics)->not->toBeNull();
    expect($ics->target_account_kind)->toBe('ics_card');
});

it('is idempotent across re-runs', function (): void {
    $user = makeUser('seeder-rerun');

    $seeder = app(DefaultKnownCounterpartyIbansSeeder::class);
    $seeder->run($user);
    $seeder->run($user);

    expect(KnownCounterpartyIban::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('firstOrCreate semantics — re-running does NOT mutate an existing alias rows notes', function (): void {
    $user = makeUser('seeder-notes-preserved');

    $seeder = app(DefaultKnownCounterpartyIbansSeeder::class);
    $seeder->run($user);

    $row = KnownCounterpartyIban::query()
        ->where('user_id', $user->id)
        ->where('real_iban', 'LU89751000135104200E')
        ->firstOrFail();
    $row->notes = 'manually-edited-by-user';
    $row->save();

    $seeder->run($user);

    $reloaded = KnownCounterpartyIban::query()
        ->where('user_id', $user->id)
        ->where('real_iban', 'LU89751000135104200E')
        ->firstOrFail();
    expect($reloaded->notes)->toBe('manually-edited-by-user');
});

it('seeds independently for two users', function (): void {
    $userA = makeUser('seeder-multi-a');
    $userB = makeUser('seeder-multi-b');

    $seeder = app(DefaultKnownCounterpartyIbansSeeder::class);
    $seeder->run($userA);
    $seeder->run($userB);

    expect(KnownCounterpartyIban::query()->where('user_id', $userA->id)->count())->toBe(2);
    expect(KnownCounterpartyIban::query()->where('user_id', $userB->id)->count())->toBe(2);
});

it('seeds user B correctly even when an HTTP request is actingAs(user A) — withoutGlobalScopes bypasses the UserScope cross-user filter', function (): void {
    // UserScope ANDs `where('user_id', auth()->id())` onto every query, so under
    // an authenticated request firstOrCreate() for another user matches nothing,
    // INSERTs, and violates the (user_id, real_iban) UNIQUE on the second pass.
    // withoutGlobalScopes() leaves the explicit user_id filter as the only one.
    $userA = makeUser('seeder-scope-a');
    $userB = makeUser('seeder-scope-b');

    /** @var App\Models\User $userAAuth */
    $userAAuth = App\Models\User::query()->findOrFail($userA->id);
    test()->actingAs($userAAuth);

    $seeder = app(DefaultKnownCounterpartyIbansSeeder::class);

    // The assertions drop the scope too, or it masks user B's rows from the
    // actingAs(userA) caller.
    $seeder->run($userB);
    expect(KnownCounterpartyIban::withoutGlobalScopes()->where('user_id', $userB->id)->count())->toBe(2);
    expect(KnownCounterpartyIban::withoutGlobalScopes()->where('user_id', $userA->id)->count())->toBe(0);

    // The second pass is where the UNIQUE constraint would fire.
    $seeder->run($userB);
    expect(KnownCounterpartyIban::withoutGlobalScopes()->where('user_id', $userB->id)->count())->toBe(2);
    expect(KnownCounterpartyIban::withoutGlobalScopes()->where('user_id', $userA->id)->count())->toBe(0);
});
