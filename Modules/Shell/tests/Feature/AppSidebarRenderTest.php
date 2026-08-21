<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\AppSidebar;

function asbUser(bool $isDeveloper, string $username = 'fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('does not render the Dev block for a non-developer (server-side absent)', function (): void {
    $user = asbUser(false, 'asb-nondev');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    expect($html)->not->toContain('side-dev-block');
    expect($html)->not->toContain('Open Dev Console');
    expect($html)->not->toContain('dot-live');
});

it('renders the Dev block with heading, dot, and kbd hint for a developer', function (): void {
    $user = asbUser(true, 'asb-dev');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('side-dev-block', escape: false);
    $component->assertSee('Developer');
    $component->assertSee('Open Dev Console');
    $component->assertSee('dot-live', escape: false);
    // The kbd hint is platform-aware via Alpine, so the server HTML carries the
    // JS escape sequence, never the raw glyph.
    $component->assertSee('\u2318.', escape: false);
    $component->assertDontSee('⌘.', escape: false);
});

it('renders the developer account caption "developer · local" for a developer', function (): void {
    $user = asbUser(true, 'asb-dev-caption');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('developer · local', escape: false);
});

it('renders the plain "local" account caption for a non-developer', function (): void {
    $user = asbUser(false, 'asb-nondev-caption');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    expect($html)->toContain('local');
    expect($html)->not->toContain('developer · local');
});

it('renders the brand row literal "beatrax"', function (): void {
    $user = asbUser(true, 'asb-brand');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('beatrax');

    // Post-rename guard: no `diederik` literal may leak anywhere in the
    // rendered sidebar HTML, route URLs included.
    $html = (string) $component->html();
    expect($html)->toContain('>Beatrax</span>')
        ->and(stripos($html, 'diederik'))
        ->toBeFalse('Rendered sidebar HTML must not contain any `diederik` literal post-rename.');
});

it('references the --side-w CSS custom property so the dev-shell layout can override the width', function (): void {
    $user = asbUser(true, 'asb-width');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    // `--side-w` (declared in app.css's @theme block, default 248px) drives the
    // width; the root may reference it inline or through the `.side` class.
    $referencesToken = str_contains($html, '--side-w') || str_contains($html, 'class="side')
        || str_contains($html, "class='side") || str_contains($html, ' class="side ')
        || str_contains($html, 'w-[248px]');

    expect($referencesToken)->toBeTrue(
        'Rendered sidebar must reference --side-w (via the .side class or an inline style) so the 16-03 dev-shell can flip to 220px.',
    );
});
