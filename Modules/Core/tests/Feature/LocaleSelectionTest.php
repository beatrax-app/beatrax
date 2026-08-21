<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Internal\Http\Middleware\SetLocale;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Symfony\Component\HttpFoundation\Response;

// The translator is reset to English first, so every case starts from a known
// state rather than from whatever the case before it left behind.
function localeAfterMiddleware(Request $request): string
{
    /** @var Translator $translator */
    $translator = Container::getInstance()->make(Translator::class);
    $translator->setLocale('en');

    /** @var SetLocale $middleware */
    $middleware = Container::getInstance()->make(SetLocale::class);
    $middleware->handle($request, fn (): Response => new Response('ok'));

    return $translator->getLocale();
}

function localeBrowserRequest(string $acceptLanguage): Request
{
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept-Language', $acceptLanguage);

    return $request;
}

function makeLocaleUser(?string $locale): User
{
    return User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

it('detects Dutch from a guest Accept-Language header', function (): void {
    expect(localeAfterMiddleware(localeBrowserRequest('nl,en;q=0.8')))->toBe('nl');
});

it('defaults a guest to English when the browser prefers an unsupported language', function (): void {
    expect(localeAfterMiddleware(localeBrowserRequest('ja-JP,ja;q=0.9')))->toBe('en');
});

it('detects every shipped language from a guest Accept-Language header', function (): void {
    foreach (Locale::cases() as $case) {
        expect(localeAfterMiddleware(localeBrowserRequest($case->value)))->toBe($case->value);
    }
});

it('honours a stored user override above the browser preference', function (): void {
    $this->actingAs(makeLocaleUser('nl'));

    expect(localeAfterMiddleware(localeBrowserRequest('en')))->toBe('nl');
});

it('falls back to browser detection when the user override is auto (null)', function (): void {
    $this->actingAs(makeLocaleUser(null));

    expect(localeAfterMiddleware(localeBrowserRequest('nl')))->toBe('nl');
});

it('persists an explicit language choice onto users.locale', function (): void {
    $user = makeLocaleUser(null);
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->call('setLocale', 'nl')
        ->assertSet('locale', 'nl');

    expect($user->fresh()->locale)->toBe('nl');
});

it('stores the auto choice as NULL — the absence of an override', function (): void {
    $user = makeLocaleUser('nl');
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->call('setLocale', 'auto')
        ->assertSet('locale', 'auto');

    expect($user->fresh()->locale)->toBeNull();
});

it('rejects an unsupported locale value', function (): void {
    $user = makeLocaleUser(null);
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->call('setLocale', 'ja')
        ->assertHasErrors('locale');

    expect($user->fresh()->locale)->toBeNull();
});

it('accepts every locale the enum declares', function (): void {
    $user = makeLocaleUser(null);
    $this->actingAs($user);

    foreach (Locale::cases() as $case) {
        Livewire::test(SettingsPage::class)
            ->call('setLocale', $case->value)
            ->assertHasNoErrors('locale');

        expect($user->fresh()->locale)->toBe($case->value);
    }
});

it('renders the settings page in Dutch when the active locale is nl', function (): void {
    $this->actingAs(makeLocaleUser('nl'));
    $this->app->make(Translator::class)->setLocale('nl');

    Livewire::test(SettingsPage::class)
        ->assertSee('Instellingen')
        ->assertSee('Instellingen opslaan')
        ->assertSee('Weergavetaal');
});
