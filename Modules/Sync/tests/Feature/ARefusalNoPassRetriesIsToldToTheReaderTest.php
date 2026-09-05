<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\SyncQuarantineNotice;
use Modules\Sync\Internal\OpLog\QuarantineOutcome;
use Modules\Sync\Internal\OpLog\QuarantineReason;

uses(RefreshDatabase::class);

// Eleven of the fifteen reasons a device refuses an operation reached nobody
// outside /dev/sync-health, which needs the developer flag. Two of them are a
// forged signature and two devices minting one id — a security event and a
// silent divergence — so the reader was the one person not being told.

function rnrUser(): User
{
    return User::query()->create([
        'username' => 'rnr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rnrRefusal(DatabaseManager $db, int $userId, QuarantineReason $reason): void
{
    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => 'transactions',
        'pk' => (string) random_int(1000, 99999),
        'device_id' => 'peer-device-id',
        'reason' => $reason->value,
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-09-01 10:00:00',
    ]);
}

function rnrSaysNothing(Testable $rendered): void
{
    foreach (QuarantineOutcome::cases() as $outcome) {
        $rendered->assertDontSee(Lang::get($outcome->bodyKey()));
    }
}

it('says nothing at all when this device has refused nothing', function (): void {
    rnrSaysNothing(Livewire::actingAs(rnrUser())->test(SyncQuarantineNotice::class));
});

it('tells the reader about every reason no later pass takes again', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rnrUser();

    foreach (QuarantineOutcome::cases() as $outcome) {
        foreach ($outcome->reasons() as $reason) {
            rnrRefusal($db, (int) $user->id, $reason);
        }
    }

    $rendered = Livewire::actingAs($user)->test(SyncQuarantineNotice::class);

    foreach (QuarantineOutcome::cases() as $outcome) {
        $rendered->assertSee(Lang::choice($outcome->summaryKey(), count($outcome->reasons())))
            ->assertSee(Lang::get($outcome->bodyKey()))
            ->assertSee(Lang::get($outcome->actionKey()));
    }
});

it('leaves the reasons that clear by themselves to the backlog notice', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rnrUser();

    foreach (QuarantineReason::recoverable() as $value) {
        rnrRefusal($db, (int) $user->id, QuarantineReason::from($value));
    }

    rnrSaysNothing(Livewire::actingAs($user)->test(SyncQuarantineNotice::class));
});

it('counts the reasons of one outcome together rather than one block each', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rnrUser();

    rnrRefusal($db, (int) $user->id, QuarantineReason::ForgedSignature);
    rnrRefusal($db, (int) $user->id, QuarantineReason::ForgedSignature);
    rnrRefusal($db, (int) $user->id, QuarantineReason::CrossUser);

    Livewire::actingAs($user)
        ->test(SyncQuarantineNotice::class)
        ->assertSee(Lang::choice(QuarantineOutcome::NotVerified->summaryKey(), 3))
        ->assertDontSee(Lang::get(QuarantineOutcome::Diverged->bodyKey()));
});

it('never counts another reader\'s refusals', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $reader = rnrUser();
    $other = rnrUser();

    rnrRefusal($db, (int) $other->id, QuarantineReason::PrimaryKeyCollision);

    rnrSaysNothing(Livewire::actingAs($reader)->test(SyncQuarantineNotice::class));
});

it('offers the reader no way to apply what the merge layer refused', function (): void {
    $declared = array_filter(
        (new ReflectionClass(SyncQuarantineNotice::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === SyncQuarantineNotice::class,
    );

    expect(array_map(static fn (ReflectionMethod $method): string => $method->getName(), array_values($declared)))
        ->toBe(['render'], 'the quarantine read-out grew a method a crafted /livewire/update payload could call');
});

it('partitions every terminal reason exactly once', function (): void {
    $seen = [];

    foreach (QuarantineOutcome::cases() as $outcome) {
        foreach ($outcome->reasons() as $reason) {
            $seen[] = $reason->value;
        }
    }

    $recoverable = QuarantineReason::recoverable();
    sort($recoverable);
    $terminal = array_map(static fn (QuarantineReason $reason): string => $reason->value, QuarantineReason::cases());
    $terminal = array_values(array_diff($terminal, $recoverable));
    sort($terminal);
    sort($seen);

    expect($seen)->toBe(array_values(array_unique($seen)), 'a reason is carried by two outcomes, so one of them is telling the reader the wrong thing')
        ->and($seen)->toBe($terminal, 'the outcomes and the recoverable set must add up to every reason, with nothing in both');
});

it('gives no terminal outcome to a reason a later pass undoes', function (): void {
    $claimed = [];

    foreach (QuarantineReason::recoverable() as $value) {
        $outcome = QuarantineOutcome::of(QuarantineReason::from($value));

        if ($outcome !== null) {
            $claimed[] = $value.' is drawn as '.$outcome->value;
        }
    }

    expect($claimed)->toBe([], implode("\n  ", [
        'A reason recoverable() owns is one a later pass retries and retires, and the backlog notice already',
        'says so. Drawing it here as well tells the same reader to go and repair something that is repairing',
        'itself. reasons() filters on recoverable() for exactly this, so a hit here means that filter is gone:',
        ...$claimed,
    ]));
});
