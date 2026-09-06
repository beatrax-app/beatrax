<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\AppChrome;
use Modules\Core\Public\Support\AppChromeResolver;
use Modules\Desktop\Public\Contracts\OsThemeSignal;

function chromeResolverFor(CurrentUser $currentUser, string $locale, bool $osBound, ?string $osTheme): AppChromeResolver
{
    $translator = test()->createStub(Translator::class);
    $translator->method('getLocale')->willReturn($locale);

    $container = test()->createStub(Container::class);
    $container->method('bound')->willReturn($osBound);

    $signal = test()->createStub(OsThemeSignal::class);
    $signal->method('currentOsTheme')->willReturn($osTheme);
    $container->method('make')->willReturn($signal);

    return new AppChromeResolver($currentUser, $translator, $container);
}

function chromeUserWithTheme(string $theme): User
{
    $user = new User;
    $user->theme = $theme;

    return $user;
}

it('resolves a guest to the system default: light, pre-paint on, request locale', function (): void {
    $currentUser = $this->createStub(CurrentUser::class);
    $currentUser->method('isAuthenticated')->willReturn(false);

    $chrome = chromeResolverFor($currentUser, 'en', osBound: false, osTheme: null)->resolve();

    expect($chrome->isDark)->toBeFalse();
    expect($chrome->needsPrePaintScript)->toBeTrue();
    expect($chrome->locale)->toBe('en');
});

it('paints dark server-side for an explicit dark user and skips the pre-paint script', function (): void {
    $currentUser = $this->createStub(CurrentUser::class);
    $currentUser->method('isAuthenticated')->willReturn(true);
    $currentUser->method('user')->willReturn(chromeUserWithTheme('dark'));

    $chrome = chromeResolverFor($currentUser, 'nl', osBound: false, osTheme: null)->resolve();

    expect($chrome->isDark)->toBeTrue();
    expect($chrome->needsPrePaintScript)->toBeFalse();
    expect($chrome->locale)->toBe('nl');
});

it('resolves a system user to dark when the OS signal reports dark', function (): void {
    $currentUser = $this->createStub(CurrentUser::class);
    $currentUser->method('isAuthenticated')->willReturn(true);
    $currentUser->method('user')->willReturn(chromeUserWithTheme('system'));

    $chrome = chromeResolverFor($currentUser, 'en', osBound: true, osTheme: 'dark')->resolve();

    expect($chrome->isDark)->toBeTrue();
    expect($chrome->needsPrePaintScript)->toBeFalse();
});

it('keeps a system user light with the pre-paint script when the OS signal is null', function (): void {
    $currentUser = $this->createStub(CurrentUser::class);
    $currentUser->method('isAuthenticated')->willReturn(true);
    $currentUser->method('user')->willReturn(chromeUserWithTheme('system'));

    $chrome = chromeResolverFor($currentUser, 'en', osBound: true, osTheme: null)->resolve();

    expect($chrome->isDark)->toBeFalse();
    expect($chrome->needsPrePaintScript)->toBeTrue();
});

// The root's theme class has three answers, not two. `light` is what every
// `html:not(.light)` guard reads as the reader's own choice, so spelling the
// unknown case `light` disarms the pre-paint script and style that exist to
// resolve exactly that case.
it('names the root theme class dark, light, or nothing at all', function (
    bool $isDark,
    bool $needsPrePaintScript,
    string $expected,
): void {
    $chrome = new AppChrome(
        isDark: $isDark,
        needsPrePaintScript: $needsPrePaintScript,
        locale: 'en',
    );

    expect($chrome->rootThemeClass())->toBe($expected);
})->with([
    'server resolved dark' => [true, false, 'dark'],
    'server resolved light' => [false, false, 'light'],
    'pre-paint script is the authority' => [false, true, ''],
]);
