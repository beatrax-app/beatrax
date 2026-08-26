<?php

declare(strict_types=1);

use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// The dot row announces its count to a screen reader, and the count was glued
// onto a bare suffix in the browser: one digit read as "1 digits entered", and
// a locale with more than two plural forms could not be served at all.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'pin-plural',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('announces one digit in the singular and two in the plural', function (): void {
    Livewire\Livewire::test(LockScreen::class)
        ->assertSee('1 digit entered', escape: false)
        ->assertSee('2 digits entered', escape: false)
        ->assertDontSee('1 digits entered', escape: false);
});

it('offers an announcement for every dot the pad can fill', function (): void {
    Livewire\Livewire::test(LockScreen::class)
        ->assertSee('0 digits entered', escape: false)
        ->assertSee('10 digits entered', escape: false);
});

// The count belongs inside the string, not glued onto it in the browser: a
// suffix concatenated in Alpine is invisible to the plural rules and to the
// contract tests that police them.
it('carries the number inside the string in every language', function (): void {
    $withoutCount = [];
    foreach (glob(base_path('Modules/Auth/Resources/lang/*/lock_screen.php')) ?: [] as $path) {
        /** @var array<string, string> $strings */
        $strings = require $path;

        if (! str_contains($strings['digits_entered'] ?? '', ':count')) {
            $withoutCount[] = basename(dirname($path));
        }
    }

    expect($withoutCount)->toBe([]);
});

it('reads the Dutch singular for one digit', function (): void {
    app()->setLocale('nl');

    expect(Lang::choice('auth::lock_screen.digits_entered', 1, ['count' => 1]))->toBe('1 cijfer ingevoerd');

    app()->setLocale('en');
});
