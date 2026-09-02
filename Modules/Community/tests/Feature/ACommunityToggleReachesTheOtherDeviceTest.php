<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// users.community_settings is registered as a synced column, and the panel
// saved the model without announcing it. The pairing backfill had already sent
// the column as null, so the toggle stayed on the device it was flipped on for
// good — the peer had no later op to apply.
function communityAnnouncements(): array
{
    $announced = [];
    foreach (Event::dispatched(EntityMutated::class) as [$event]) {
        if ($event->table === 'users' && array_key_exists('community_settings', $event->dirtyFields)) {
            $announced[] = $event;
        }
    }

    return $announced;
}

it('announces the column when a shared-list toggle is flipped', function (): void {
    $user = User::create([
        'username' => 'community-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    Event::fake([EntityMutated::class]);

    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');

    $announced = communityAnnouncements();
    expect($announced)->toHaveCount(1);
    expect($announced[0]->mutationType)->toBe('edit');
    expect($announced[0]->userId)->toBe($user->id);
});

it('announces the stored column, so the peer receives both switches', function (): void {
    $user = User::create([
        'username' => 'community-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    Event::fake([EntityMutated::class]);

    Livewire::test(SharedListSettingsPanel::class)
        ->call('toggleUseSharedList')
        ->call('toggleOfferToContribute');

    $announced = communityAnnouncements();
    expect($announced)->toHaveCount(2);

    // Read back from the row rather than echoed from the toggle: the column is
    // one JSON map holding both switches, and a peer handed only the switch
    // that just moved would lose the other.
    $last = json_decode((string) $announced[1]->dirtyFields['community_settings'], true);
    expect($last)->toHaveKeys(['useSharedList', 'offerToContribute']);
});
