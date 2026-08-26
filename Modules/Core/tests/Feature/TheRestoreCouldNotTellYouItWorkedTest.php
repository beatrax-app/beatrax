<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Public\Support\Lang;

// Sessions live in the database, so restoring one replaces the row the current
// sign-in is held by: the app goes straight to the lock screen and the
// component that would have said "Restored." is never rendered again. The
// success line is therefore unreachable on every shipped configuration, and
// the only place the consequence can be stated is before the button.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'restore-says-nothing',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('ships the database session driver the warning is about', function (): void {
    expect(app(Repository::class)->get('session.driver'))->toBe('database');
});

it('says before the button that restoring signs you out', function (): void {
    Livewire::test(EncryptedBackupRestore::class)
        ->assertSee('signed out');

    expect(Lang::get('core::backup.restore.intro_html'))->toContain('signed out');
});
