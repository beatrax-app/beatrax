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
