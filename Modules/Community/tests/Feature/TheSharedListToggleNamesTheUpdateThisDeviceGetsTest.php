<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;

// "every time Beatrax updates itself" is the desktop's electron-updater chain,
// and every listener in it returns early on a mobile runtime. A phone is
// updated by its store, so on a phone this toggle described a trigger that
// never fires.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = makeCommunityTestUser('shared-list-update-trigger');
    $this->actingAs($this->reader);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('keeps the self-updating wording on the desktop, where Beatrax really does update itself', function (): void {
    Livewire::test(SharedListSettingsPanel::class)
        ->assertSet('onPhone', false)
        ->assertSee('every time Beatrax updates itself');
});

it('names the store update on a phone, where Beatrax never updates itself', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(SharedListSettingsPanel::class)
        ->assertSet('onPhone', true)
        ->assertDontSee('every time Beatrax updates itself')
        ->assertSee('App Store or Google Play');
});

it('leaves the toggle disabled and version-agnostic on both platforms', function (): void {
    foreach ([null, 'ios'] as $platform) {
        $platform === null ? putenv('NATIVEPHP_PLATFORM') : putenv('NATIVEPHP_PLATFORM='.$platform);

        $html = Livewire::test(SharedListSettingsPanel::class)->html();

        expect(preg_match('/<button[^>]*id="toggle-update-on-updates"[^>]*disabled/i', $html))->toBe(1)
            ->and(preg_match('/\b\d+\.\d+/', $html))->toBe(0);
    }
});
