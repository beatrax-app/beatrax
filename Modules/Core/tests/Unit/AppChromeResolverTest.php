<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
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
