<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('shared-list-settings-user');
    $this->actingAs($this->user);
});

it('renders all three toggles with Toggle 3 disabled and a version-agnostic inline note (B-5)', function (): void {
    $component = Livewire::test(SharedListSettingsPanel::class);

    $html = $component->html();
    expect($html)->toContain('Use the shared merchant list');
    expect($html)->toContain('Offer to contribute');
    expect($html)->toContain('Update the shared list on app updates');

    // A role="switch" button now, not a styled checkbox: these are on/off
    // settings and a screen reader has to announce them as one thing.
    expect($html)->toContain('id="toggle-update-on-updates"');
    expect(preg_match('/<button[^>]*id="toggle-update-on-updates"[^>]*disabled/i', $html))->toBe(1);
    expect(preg_match('/<button[^>]*role="switch"[^>]*id="toggle-update-on-updates"/i', $html))->toBe(1);

    // The note must promise a future activation without naming a version:
    // no `N.M` shape may appear anywhere in the rendered panel.
    expect($html)->toContain('Activates with a future app update');
    expect(preg_match('/\b\d+\.\d+/', $html))->toBe(0);
});

it('persists Toggle 1 (useSharedList) state to users.community_settings on toggle', function (): void {
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');

    $settings = $this->user->fresh()->community_settings;
    expect($settings)->toBeArray();
    expect($settings)->toHaveKey('useSharedList');
    expect($settings['useSharedList'])->toBeFalse();
});

it('persists Toggle 2 (offerToContribute) state to users.community_settings on toggle', function (): void {
    Livewire::test(SharedListSettingsPanel::class)->call('toggleOfferToContribute');

    $settings = $this->user->fresh()->community_settings;
    expect($settings)->toBeArray();
    expect($settings['offerToContribute'])->toBeFalse();
});

it('does not write to users.community_settings when Toggle 3 (updateOnAppUpdates) is invoked (no-op)', function (): void {
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUpdateOnAppUpdates');

    $settings = $this->user->fresh()->community_settings;
    if ($settings === null) {
        expect($settings)->toBeNull();
    } else {
        expect($settings['updateOnAppUpdates'] ?? false)->toBeFalse();
    }
});
