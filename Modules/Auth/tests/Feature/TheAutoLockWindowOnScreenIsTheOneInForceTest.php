<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\IdleTimeoutOptions;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// A fresh install gets IdleTimeoutOptions::DEFAULT_MINUTES, which is five. The
// window select lists one minute first and marked nothing, so a browser drew
// "1 minute" over a five-minute lock. The reader could not correct it to one
// minute either: selecting the option already on screen fires no change event,
// so setIdleTimeout never ran.

beforeEach(function (): void {
    $this->reader = User::query()->forceCreate([
        'username' => 'idle-window-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('auto-lock-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);
});

it('opens on the window a fresh install is actually locked by', function (): void {
    $html = Livewire::test(AppLockSettingsSection::class)
        ->assertSet('idleTimeoutMinutes', IdleTimeoutOptions::DEFAULT_MINUTES)
        ->html();

    expect($html)->toContain('<option value="5" selected>')
        ->and($html)->not->toContain('<option value="1" selected>');
});

it('follows the stored window rather than the first one offered', function (): void {
    DB::table('user_app_lock_configs')->updateOrInsert(
        ['user_id' => $this->reader->id],
        [
            'lock_enabled' => true,
            'idle_timeout_minutes' => 30,
            'failed_attempts' => 0,
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ],
    );

    $html = Livewire::test(AppLockSettingsSection::class)->html();

    expect($html)->toContain('<option value="30" selected>')
        ->and($html)->not->toContain('<option value="5" selected>');
});
