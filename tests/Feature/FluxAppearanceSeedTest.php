<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// Flux stores its appearance in localStorage and applies `system` whenever the
// key is absent. The stored preference lives in the database here, so without
// a seed Flux re-decided the theme from the operating system on every load —
// adding `.dark` back for a reader who chose Light on a dark device, and
// stripping it for one who chose Dark on a light device.
function seedUser(string $username, string $theme): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'theme' => $theme,
    ]);

    return $user;
}

it('tells Flux the stored theme is light, above the script that reads it', function (): void {
    $html = $this->actingAs(seedUser('flux-light', 'light'))->get('/settings')->content();

    expect($html)->toContain("window.localStorage.setItem('flux.appearance', 'light')");

    // Order is the whole fix: Flux reads the key as its own script runs, so a
    // seed emitted afterwards would apply one paint too late.
    expect(strpos($html, "setItem('flux.appearance', 'light')"))
        ->toBeLessThan((int) strpos($html, 'window.Flux'));
});

it('tells Flux the stored theme is dark', function (): void {
    $html = $this->actingAs(seedUser('flux-dark', 'dark'))->get('/settings')->content();

    expect($html)->toContain("window.localStorage.setItem('flux.appearance', 'dark')");
});

// `system` means the operating system is authoritative, which is exactly what
// Flux does with no key — so the seed has to clear a stale one rather than
// write a value.
it('clears the key when the reader has chosen to follow the system', function (): void {
    $html = $this->actingAs(seedUser('flux-system', 'system'))->get('/settings')->content();

    // Flux's own body writes every appearance value it supports, so a negative
    // against the whole page matches Flux rather than the seed. Only the markup
    // above Flux's script is ours.
    $seed = substr($html, 0, (int) strpos($html, 'window.Flux'));

    expect($seed)->toContain("window.localStorage.removeItem('flux.appearance')")
        ->and($seed)->not->toContain("setItem('flux.appearance'");
});

// The seed above only runs on a full page load, and choosing a theme in
// settings is a Livewire update: no request is made that could re-emit it.
// Measured on a Galaxy S24 Ultra -- Light chosen, then the phone's own night
// mode flipped, and the page went dark under a toggle that still read Light,
// because Flux's copy of the choice still said `system`.
// The key is not durable: read over the DevTools protocol on a Galaxy S24
// Ultra after a night-mode change, the WebView's localStorage was empty and
// Flux had fallen back to `system` and painted the page dark under a Theme
// toggle still reading Light. The choice is therefore also published on the
// root, where it lasts as long as the document.
it('publishes the choice on the root, which needs no storage to survive', function (): void {
    foreach (['light', 'dark', 'system'] as $theme) {
        $html = $this->actingAs(seedUser('flux-root-'.$theme, $theme))->get('/settings')->content();

        expect($html)->toContain("document.documentElement.dataset.themeChoice = '".$theme."'");
    }
});

// A reader on Light whose phone flips to night mode: nothing in the app knows
// unless the page re-asserts, and Flux answers the same media query with its
// own answer -- so this has to run after it rather than beside it.
it('re-asserts that choice when the operating system changes under it', function (): void {
    $app = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($app)->toContain('function applyThemeChoice()')
        ->and($app)->toContain("root.dataset.themeChoice ?? 'system'")
        ->and($app)->toContain('requestAnimationFrame(applyThemeChoice)');
});

it('writes the same key from the page when the choice changes without a load', function (): void {
    $app = (string) file_get_contents(base_path('resources/js/app.js'));

    $listener = substr($app, (int) strpos($app, "document.addEventListener('theme-changed'"));
    $listener = substr($listener, 0, (int) strpos($listener, "\n});"));

    expect($listener)->toContain("window.localStorage.setItem('flux.appearance', chosen)")
        ->and($listener)->toContain("window.localStorage.removeItem('flux.appearance')")
        // Only the two explicit choices are values; anything else -- `system`,
        // and the null a malformed event carries -- clears the key, which is
        // what Flux reads as "follow the OS".
        ->and($listener)->toContain("chosen === 'light' || chosen === 'dark'");
});
