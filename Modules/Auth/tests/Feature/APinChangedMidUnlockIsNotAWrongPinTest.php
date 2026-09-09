<?php

declare(strict_types=1);

// The derivation runs outside the write transaction, so a PIN change can
// commit underneath it. The attempt then describes material the row no longer
// holds and records neither outcome -- correctly. What the reader was told did
// not follow: the screen said the PIN was incorrect and named a remaining
// count that never moved, because nothing was counted. A person typing their
// new, correct PIN was told it was wrong, twice over.

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

function outracedUnlockUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'account-password',
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    test()->session([AppLockTestHarness::LOCKED_SESSION_KEY => true]);

    return $user;
}

// Re-wraps the row once, on the first read taken after the listener is armed,
// which is the read the derivation runs against.
function rewrapOnNextRead(User $user): Closure
{
    $rewrapped = false;

    Event::listen(function (QueryExecuted $event) use (&$rewrapped, $user): void {
        if ($rewrapped || ! str_contains($event->sql, 'user_app_lock_configs')) {
            return;
        }

        if (! str_starts_with(strtolower($event->sql), 'select')) {
            return;
        }

        $rewrapped = true;
        DB::connection()->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->update(['pin_wrapped_key' => base64_encode(random_bytes(60))]);
    });

    return function () use (&$rewrapped): bool {
        return $rewrapped;
    };
}

it('does not call an outraced unlock an incorrect PIN on the desktop lock screen', function (): void {
    $user = outracedUnlockUser('outraced-desktop');

    $component = Livewire::test(LockScreen::class);
    $didRewrap = rewrapOnNextRead($user);

    $component->call('submit', '123456')->assertNoRedirect();

    expect($didRewrap())->toBeTrue('The re-wrap has to land between the read and the write, or this proves nothing.');

    /** @var string $flash */
    $flash = $component->get('flashMessage');

    expect($flash)->toBe(Lang::get('auth::lock_screen.error_pin_changed'))
        ->and($flash)->not->toContain('Incorrect PIN')
        ->and($flash)->not->toMatch('/\d+ attempts? remaining/');
});

it('leaves the failure meter the outraced attempt did not move out of the copy', function (): void {
    $user = outracedUnlockUser('outraced-meter');

    $component = Livewire::test(LockScreen::class);
    rewrapOnNextRead($user);

    $component->call('submit', '123456');

    /** @var stdClass $row */
    $row = DB::connection()->table('user_app_lock_configs')->where('user_id', $user->id)->first();

    expect((int) $row->failed_attempts)->toBe(0)
        ->and(app(LockStateManager::class)->isLocked(app('session.store')))->toBeTrue();

    // The count is the half that contradicted itself: stable across retries
    // while the sentence above it claimed each one had been spent.
    expect((string) $component->get('flashMessage'))->not->toContain('10');
});

it('still names an incorrect PIN as one when nothing raced it', function (): void {
    outracedUnlockUser('unoutraced-desktop');

    Livewire::test(LockScreen::class)
        ->call('submit', '000000')
        ->assertNoRedirect()
        ->assertSee('Incorrect PIN');
});
