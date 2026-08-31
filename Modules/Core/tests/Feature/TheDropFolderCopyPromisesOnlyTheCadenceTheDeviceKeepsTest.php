<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\AutoImportSettingsSection;

// The iPhone read "Beatrax scans storage/app/inbox-drop/1/ every 5 minutes".
// A phone's background runner clamps anything under fifteen minutes to fifteen
// and hands the interval to iOS BGTaskScheduler, which decides for itself when
// — and whether — a registered task gets a turn. Five minutes is the desktop's
// real cadence and a promise no phone can keep.

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');

    $this->reader = User::create([
        'username' => 'drop-copy',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('keeps the five-minute cadence on the desktop, where the scheduler really runs it', function (): void {
    Livewire::test(AutoImportSettingsSection::class)
        ->assertSee('every 5 minutes')
        ->set('enabled', true)
        ->assertSee('every 5 minutes');
});

it('promises a phone no cadence its scheduler can keep', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(AutoImportSettingsSection::class)
        ->assertDontSee('every 5 minutes')
        ->assertSee('Your phone decides when a background scan runs')
        ->set('enabled', true)
        ->assertDontSee('every 5 minutes')
        ->assertSee('Your phone decides when a background scan runs');
});

it('still names the drop folder and the processed subfolder on a phone', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');

    Livewire::test(AutoImportSettingsSection::class)
        ->assertSee('inbox-drop/'.$this->reader->id.'/')
        ->assertSee('/processed/{YYYY-MM}/');
});
