<?php

declare(strict_types=1);

// The phone mirrors the desktop lock screen line for line, and it mirrored the
// defect too: an unlock outraced by a PIN change was reported as an incorrect
// PIN beside a remaining count that had not moved, because the attempt was
// correctly never counted.

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;

function outracedPhoneUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'account-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    test()->session([AppLockTestHarness::LOCKED_SESSION_KEY => true]);

    return $user;
}

// Re-wraps the row once, on the first read taken after the listener is armed,
// which is the read the derivation runs against.
function rewrapPhoneRowOnNextRead(User $user): void
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
}

it('does not call an outraced unlock an incorrect PIN on the phone', function (): void {
    $user = outracedPhoneUser('outraced-phone');

    $component = Livewire::test(MobileLockScreen::class);
    rewrapPhoneRowOnNextRead($user);

    $component->call('submit', '123456')->assertNoRedirect();

    /** @var string $flash */
    $flash = $component->get('flashMessage');

    expect($flash)->toBe(Lang::get('mobile::lock.errors.pin_changed'))
        ->and($flash)->not->toContain('Incorrect PIN')
        ->and($flash)->not->toMatch('/\d+ attempts? remaining/');

    /** @var stdClass $row */
    $row = DB::connection()->table('user_app_lock_configs')->where('user_id', $user->id)->first();

    expect((int) $row->failed_attempts)->toBe(0);
});

it('says the same thing the desktop lock screen says', function (): void {
    expect(Lang::get('mobile::lock.errors.pin_changed'))
        ->toBe(Lang::get('auth::lock_screen.error_pin_changed'));
});

it('still names an incorrect PIN as one on the phone when nothing raced it', function (): void {
    outracedPhoneUser('unoutraced-phone');

    Livewire::test(MobileLockScreen::class)
        ->call('submit', '000000')
        ->assertNoRedirect()
        ->assertSee('Incorrect PIN');
});
