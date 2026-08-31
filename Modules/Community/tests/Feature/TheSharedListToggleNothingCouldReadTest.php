<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Community\Public\Services\CommunitySettings;

// "Use the shared merchant list" was written to users.community_settings by the
// panel and read by nobody: the resolver consulted the corpus whichever way the
// switch stood. Its sibling toggle IS honoured, so the panel looked like it
// worked. The reader below is the surface a consumer gates on.

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('shared-list-toggle-user');
    $this->actingAs($this->user);
    $this->settings = app(CommunitySettings::class);
});

it('reports the shared list off for a reader who switched it off', function (): void {
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');

    expect($this->settings->usesSharedList($this->user->id))->toBeFalse();
});

it('reports the shared list on for a reader who never opened the panel', function (): void {
    expect($this->settings->usesSharedList($this->user->id))->toBeTrue()
        ->and($this->settings->enabled(CommunitySetting::UpdateOnAppUpdates, $this->user->id))->toBeFalse();
});

it('reads the toggle back on once the reader switches it on again', function (): void {
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');

    expect($this->settings->usesSharedList($this->user->id))->toBeTrue();
});

it('keeps the contribute toggle where it was when the shared list goes off', function (): void {
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');

    expect($this->settings->offersToContribute($this->user->id))->toBeTrue();
});

it('answers for the reader whose id it was given, not the one signed in', function (): void {
    $other = makeCommunityTestUser('shared-list-toggle-other');
    Livewire::test(SharedListSettingsPanel::class)->call('toggleUseSharedList');

    expect($this->settings->usesSharedList($other->id))->toBeTrue()
        ->and($this->settings->usesSharedList($this->user->id))->toBeFalse();
});
