<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Community\Public\Services\CommunitySettings;
use Modules\Core\Models\User;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// The switches carry wire:click only, so both flags are the server's. Each
// toggle negates the property it then persists — so unlocked it negated the
// CLIENT's value rather than the stored one, and a payload naming the flag it
// wanted the opposite of chose the saved setting outright. That defeats the
// reader's own tap: pressing "off" on a flag the payload said was already off
// stored it back on, and this one gates merchant auto-naming.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'shared-list-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

function sharedListFlag(int $userId, CommunitySetting $setting): bool
{
    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $settings = is_array($user->community_settings) ? $user->community_settings : [];

    return CommunitySettings::readFrom($settings, $setting);
}

function sharedListPanelSnapshot(): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) test()->get('/community')->assertOk()->getContent(),
        'community.shared-list-settings-panel',
    );
}

it('refuses a payload that chooses what the shared-list toggle stores', function (): void {
    expect(sharedListFlag($this->user->id, CommunitySetting::UseSharedList))->toBeTrue();

    LivewireRoundTrip::tamper(
        $this,
        sharedListPanelSnapshot(),
        ['useSharedList' => false],
        [['path' => '', 'method' => 'toggleUseSharedList', 'params' => []]],
    )->assertForbidden();

    expect(sharedListFlag($this->user->id, CommunitySetting::UseSharedList))->toBeTrue();
});

it('refuses a payload that chooses what the contribute toggle stores', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        sharedListPanelSnapshot(),
        ['offerToContribute' => false],
        [['path' => '', 'method' => 'toggleOfferToContribute', 'params' => []]],
    )->assertForbidden();

    expect(sharedListFlag($this->user->id, CommunitySetting::OfferToContribute))->toBeTrue();
});

// The half the refusal above cannot show on its own: with the client's value
// gone, the tap has to still turn the stored flag over. Before the lock this
// same payload left it on, because the toggle negated the forged false.
it('still turns the stored flag off when the switch is tapped', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        sharedListPanelSnapshot(),
        [],
        [['path' => '', 'method' => 'toggleUseSharedList', 'params' => []]],
    )->assertOk();

    expect(sharedListFlag($this->user->id, CommunitySetting::UseSharedList))->toBeFalse();
});
