<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Modules\Core\Public\Support\PatternScan;

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('shared-list-settings-user');
    $this->actingAs($this->user);
});

it('renders all three toggles with Toggle 3 disabled and a version-agnostic inline note', function (): void {
    $component = Livewire::test(SharedListSettingsPanel::class);

    $html = $component->html();
    expect($html)->toContain('Use the shared merchant list');
    expect($html)->toContain('Offer to contribute');
    expect($html)->toContain('Update the shared list on app updates');

    // A role="switch" button now, not a styled checkbox: these are on/off
    // settings and a screen reader has to announce them as one thing.
    expect($html)->toContain('id="toggle-update-on-updates"');
    expect(PatternScan::matches('/<button[^>]*id="toggle-update-on-updates"[^>]*disabled/i', $html))->toBeTrue();
    expect(PatternScan::matches('/<button[^>]*role="switch"[^>]*id="toggle-update-on-updates"/i', $html))->toBeTrue();

    // The note must promise a future activation without naming a version:
    // no `N.M` shape may appear anywhere in the rendered panel.
    expect($html)->toContain('Activates with a future app update');
    expect(PatternScan::matches('/\b\d+\.\d+/', $html))->toBeFalse();
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

// The panel computed mappingsCount and contributorCount on every render and
// the blade referenced neither, while its own comment promised "the stats ride
// under the intro as a caption" — two lang keys with no reader, carried in all
// 26 locales.
it('renders the mapping and contributor counts the panel computes', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    foreach ([['a', 'bundled'], ['b', 'bundled'], ['c', 'someone-else']] as [$pattern, $contributor]) {
        $db->connection()->table('community_merchant_mappings')->insert([
            'user_id' => null,
            'pattern' => strtoupper($pattern),
            'generalized_pattern' => $pattern,
            'name' => 'Name '.$pattern,
            'contributor' => $contributor,
            'created_at' => '2026-08-15T10:00:00Z',
            'updated_at' => '2026-08-15T10:00:00Z',
        ]);
    }

    $html = Livewire::test(SharedListSettingsPanel::class)->html();

    expect($html)->toContain('data-testid="shared-list-stats"');
    expect($html)->toContain('3 mappings');
    expect($html)->toContain('2 contributors');
});
