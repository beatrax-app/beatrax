<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;

/*
 * AppSidebar rendering invariants.
 *
 *  (1) Authenticated NON-developer → renders → response does NOT
 *      contain the literal substring "side-dev-block". The Dev
 *      block is server-side absent for non-developers so the Dev
 *      Console's existence is never disclosed via HTML.
 *  (2) Authenticated developer (is_developer=true) → renders →
 *      response contains "side-dev-block" + the literal "Developer"
 *      heading + the literal "dot-live" class + the platform-aware
 *      kbd hint binding (JS escape \u2318. — never the raw glyph, IN-03).
 *  (3) The account caption renders "developer · local" for developers
 *      and "local" for non-developers.
 *  (4) The brand row literal is `beatrax` (post-rename string per
 *      D-10), NOT `beatrax` — guards the rename precondition.
 *  (5) The rendered `<aside>` references the `--side-w` CSS custom
 *      property (or the matching 248px width token) somewhere on the
 *      sidebar root, so 16-03's dev-shell can flip it to 220px.
 */

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
    // IN-03: the dev-console kbd hint is platform-aware via Alpine —
    // the server HTML carries the JS escape sequence, never the raw glyph.
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
    // The caption "developer · local" must not appear for non-developers.
    expect($html)->not->toContain('developer · local');
});

it('renders the brand row literal "beatrax" (post-rename lock per D-10)', function (): void {
    $user = asbUser(true, 'asb-brand');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('beatrax');

    // The brand-row text must be `beatrax`. Post-rename guard: no
    // `diederik` literal may leak anywhere in the rendered sidebar
    // HTML. Route URLs already resolve against `https://beatrax.test`
    // (APP_URL was flipped in 16-02 alongside the brand row), so a
    // single grep across the entire rendered HTML for any case-
    // insensitive `diederik` substring is a tight regression guard.
    $html = (string) $component->html();
    expect($html)->toContain('>Beatrax</span>')
        ->and(stripos($html, 'diederik'))
        ->toBeFalse('Rendered sidebar HTML must not contain any `diederik` literal post-rename.');
});

it('references the --side-w CSS custom property so the dev-shell layout can override the width', function (): void {
    $user = asbUser(true, 'asb-width');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    // The `--side-w` token drives the sidebar width (declared in the
    // @theme block of resources/css/app.css; defaulted to 248px). The
    // rendered <aside> root references it either inline or through the
    // `.side` class. We assert either form is present.
    $referencesToken = str_contains($html, '--side-w') || str_contains($html, 'class="side')
        || str_contains($html, "class='side") || str_contains($html, ' class="side ')
        || str_contains($html, 'w-[248px]');

    expect($referencesToken)->toBeTrue(
        'Rendered sidebar must reference --side-w (via the .side class or an inline style) so the 16-03 dev-shell can flip to 220px.',
    );
});
