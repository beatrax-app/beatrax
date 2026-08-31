<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockPinShape;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;

// AppLockPinShape exists so that what a PIN is lives in one place. The lock
// screen carried its own copy of the rule as a regex, and the sentence it
// showed carried a fourth copy as a literal digit.

function pinRuleReader(): User
{
    $user = User::query()->create([
        'username' => 'pin-rule-'.bin2hex(random_bytes(4)),
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);
    test()->session([LockStateManager::SESSION_KEY => true]);

    return $user;
}

it('refuses a PIN one digit under the shared minimum', function (): void {
    pinRuleReader();

    Livewire::test(LockScreen::class)
        ->call('submit', str_repeat('1', AppLockPinShape::MINIMUM_LENGTH - 1))
        ->assertSet('flashMessage', Lang::get('auth::lock_screen.error_pin_shape', [
            'min' => AppLockPinShape::MINIMUM_LENGTH,
            'max' => AppLockPinShape::MAXIMUM_LENGTH,
        ]));
});

it('refuses a PIN one digit over the shared maximum, which the old gate admitted', function (): void {
    pinRuleReader();

    Livewire::test(LockScreen::class)
        ->call('submit', str_repeat('1', AppLockPinShape::MAXIMUM_LENGTH + 1))
        ->assertSet('flashMessage', Lang::get('auth::lock_screen.error_pin_shape', [
            'min' => AppLockPinShape::MINIMUM_LENGTH,
            'max' => AppLockPinShape::MAXIMUM_LENGTH,
        ]));
});

it('names the bounds from the constants rather than from the sentence', function (): void {
    pinRuleReader();

    $rendered = Lang::get('auth::lock_screen.error_pin_shape', [
        'min' => AppLockPinShape::MINIMUM_LENGTH,
        'max' => AppLockPinShape::MAXIMUM_LENGTH,
    ]);

    expect($rendered)
        ->toContain((string) AppLockPinShape::MINIMUM_LENGTH)
        ->toContain((string) AppLockPinShape::MAXIMUM_LENGTH);
});

it('leaves the bounds out of the copy in every locale it ships', function (): void {
    $missing = [];

    foreach (Locale::cases() as $locale) {
        $file = base_path('Modules/Auth/Resources/lang/'.$locale->value.'/lock_screen.php');
        if (! is_file($file)) {
            continue;
        }

        /** @var array<string, string> $lines */
        $lines = require $file;
        $sentence = $lines['error_pin_shape'] ?? '';

        if (! str_contains($sentence, ':min') || ! str_contains($sentence, ':max')) {
            $missing[] = $locale->value;
        }
    }

    expect($missing)->toBe([], 'these locales spell the PIN bounds out instead of taking them from AppLockPinShape');
});
