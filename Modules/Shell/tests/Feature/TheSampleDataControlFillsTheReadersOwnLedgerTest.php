<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;
use Modules\Shell\Internal\Http\Livewire\SampleDataCard;

// `demo:seed` mints two accounts — one of them a developer, both with the same
// published password — which is why it could not simply be put behind a button
// a store build carries. The control seeds the reader's own account instead.

function sdlReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('asks before it writes', function (): void {
    $reader = sdlReader('sdl-asks');

    Livewire::actingAs($reader)
        ->test(SampleDataCard::class)
        ->assertSet('confirming', false)
        ->call('ask')
        ->assertSet('confirming', true)
        ->call('cancel')
        ->assertSet('confirming', false);

    expect(DB::table('transactions')->where('user_id', $reader->id)->count())->toBe(0);
});

it('fills the reader own ledger rather than inventing an account to fill', function (): void {
    $reader = sdlReader('sdl-fills');

    Livewire::actingAs($reader)
        ->test(SampleDataCard::class)
        ->call('load')
        ->assertSet('confirming', false);

    expect(DB::table('transactions')->where('user_id', $reader->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('accounts')->where('user_id', $reader->id)->count())->toBeGreaterThan(0);

    expect(User::query()->whereIn('username', DemoUsersSeeder::usernames())->count())->toBe(0);
});

// The two accounts `demo:seed` creates are `demo-1` (a developer) and `demo-2`,
// both with the same published password. A control a store build carries must
// not be able to mint either.
it('mints no account and grants nobody the developer flag', function (): void {
    $reader = sdlReader('sdl-no-accounts');
    $before = User::query()->count();

    Livewire::actingAs($reader)->test(SampleDataCard::class)->call('load');

    expect(User::query()->count())->toBe($before)
        ->and(User::query()->where('is_developer', true)->count())->toBe(0);
});

// Recovery codes and wizard progress are the install's own state. Seeding them
// over a real account would replace the reader's codes and reopen onboarding.
it('leaves the install own state alone', function (): void {
    $reader = sdlReader('sdl-install-state');

    Livewire::actingAs($reader)->test(SampleDataCard::class)->call('load');

    expect(UserRecoveryCode::query()->where('user_id', $reader->id)->count())->toBe(0);
});

it('says how much it added', function (): void {
    $reader = sdlReader('sdl-reports');

    $rows = Livewire::actingAs($reader)
        ->test(SampleDataCard::class)
        ->assertSet('loadedRows', null)
        ->call('load')
        ->get('loadedRows');

    expect($rows)->toBeInt()->toBeGreaterThan(0);
});
