<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

// Named apart from the fixtures in CounterpartyTriageTest: both files are
// loaded into one process, and a second global of the same name is a fatal.
function triageBarUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function triageBarUnknown(int $userId, string $slug): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'unknown',
        'slug' => $slug,
        'display_name' => $slug,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// The queue is re-read on every render and a labelled counterparty is no longer
// unknown, so the list it returns shrinks as the session goes. Reading its
// length as the denominator walked both ends of the fraction towards each
// other: the bar reached 100 % at the halfway point and told the reader nothing
// was left while half the queue still was. Measured on a phone, six of twelve.
it('does not reach the end of the bar while the queue still has work in it', function (): void {
    $user = triageBarUser('triage-progress-fixture');
    $this->actingAs($user);

    foreach (range(1, 6) as $n) {
        triageBarUnknown($user->id, 'unknown-counterparty-'.$n);
    }

    $component = Livewire::test(CounterpartyTriage::class)
        ->assertViewHas('total', 6)
        ->assertViewHas('seen', 0)
        ->assertViewHas('percent', 0)
        ->assertViewHas('remainingCount', 6);

    // Halfway: three handled, three still unknown. The denominator must not
    // have moved with the numerator.
    foreach (range(1, 3) as $ignored) {
        $component->call('markIgnored');
    }

    $component
        ->assertViewHas('seen', 3)
        ->assertViewHas('total', 6)
        ->assertViewHas('remainingCount', 3)
        ->assertViewHas('percent', 50);
});

it('reads a hundred per cent only once nothing is left to label', function (): void {
    $user = triageBarUser('triage-completion-fixture');
    $this->actingAs($user);

    foreach (range(1, 4) as $n) {
        triageBarUnknown($user->id, 'unknown-to-finish-'.$n);
    }

    $component = Livewire::test(CounterpartyTriage::class);

    foreach (range(1, 4) as $ignored) {
        $component->call('markIgnored');
    }

    $component
        ->assertViewHas('seen', 4)
        ->assertViewHas('total', 4)
        ->assertViewHas('remainingCount', 0)
        ->assertViewHas('percent', 100)
        ->assertViewHas('minutesRemaining', 0)
        ->assertViewHas('queueEmpty', true);
});
