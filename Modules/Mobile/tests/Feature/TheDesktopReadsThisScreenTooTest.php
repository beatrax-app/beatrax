<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;

uses(RefreshDatabase::class);

// /data-devices is plain web+auth with no platform gate, an unconditional
// sidebar row and a palette entry, so every line of this screen renders on the
// desktop as well. Two of them were written for a phone and only a phone: a
// cellular pause nothing on a desktop reads, and a note denying the background
// listener that sync:serve has been running since boot.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = User::query()->create([
        'username' => 'desktop-reads-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('desktop-reads-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('keeps the cellular pause off a machine whose sync path never reads it', function (): void {
    $html = Livewire::test(SyncScreen::class)->assertSet('onPhone', false)->html();

    expect($html)->not->toContain('data-testid="sync-network"')
        ->and($html)->not->toContain(e(Lang::get('mobile::sync.pause_cellular')));
});

it('still offers the cellular pause on the device that honours it', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $html = Livewire::test(SyncScreen::class)->assertSet('onPhone', true)->html();

    expect($html)->toContain('data-testid="sync-network"')
        ->and($html)->toContain(e(Lang::get('mobile::sync.pause_cellular')));
});

it('does not deny a background listener to the platform that runs one', function (): void {
    $html = Livewire::test(SyncScreen::class)->html();

    expect($html)->toContain(e(Lang::get('mobile::sync.background_note')))
        ->and($html)->not->toContain(e(Lang::get('mobile::sync.background_note_phone')));
});

it('keeps the tap-only note for the device that really cannot listen', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    $html = Livewire::test(SyncScreen::class)->html();

    expect($html)->toContain(e(Lang::get('mobile::sync.background_note_phone')))
        ->and($html)->not->toContain(e(Lang::get('mobile::sync.background_note')));
});
