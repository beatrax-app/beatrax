<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen;

uses(RefreshDatabase::class);

// `mobile.setup` sits on AppLockMiddleware::ALLOWED_ROUTE_NAMES so a wire:poll
// tick never trips the PIN screen — the poll is not activity, and the lock used
// to drop over a working transfer. The cost nobody covered: once the lock
// engages anyway, nothing redirects the reader, the poll answers Locked on
// every tick, and the screen printed "Unlock the app to continue setting up."
// with no control to do it. Found on a paired Galaxy S24 that idled mid-sync:
// backgrounding and relaunching returned to the same frame.
//
// state() answers Locked on the key-file EXISTING and the KEK being out of
// reach — it asks for the key before opening the file — so a placeholder at
// that path plus a session that never unlocked is the field's own arrangement.
function lockedSetupUser(): User
{
    $user = User::query()->create([
        'username' => 'locked-setup-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('locked-setup-pass'),
        'period_start_day' => 1,
    ]);

    $path = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, 'sealed');

    return $user;
}

it('renders a way to the lock screen when the app-lock engaged mid-setup', function (): void {
    Livewire::actingAs(lockedSetupUser())
        ->test(SetupProgressScreen::class)
        ->call('poll')
        ->assertSee('data-testid="setup-unlock-link"', escape: false)
        ->assertSee(route('mobile.lock'), escape: false);
});
