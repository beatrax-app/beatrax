<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Models\KnownCounterpartyIban;

uses(RefreshDatabase::class);

/**
 * Locks the seeder's behavioral invariants:
 *  - Two specific institution-IBAN aliases land for a fresh user.
 *  - Re-running the seeder is idempotent (firstOrCreate, not
 *    updateOrCreate — so an edited `notes` field stays untouched).
 *  - Per-user scoping: two users get two rows each, independently.
 */
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
