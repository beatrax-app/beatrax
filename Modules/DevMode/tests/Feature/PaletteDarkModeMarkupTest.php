<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;

/*
 * Palette modal dark-mode markup invariant.
 *
 * The command palette renders inside the dev-shell + main app
 * layouts which set `.dark` on <html> from the user's appearance
 * preference. Every coloured surface inside the palette modal must
 * carry an explicit `dark:` Tailwind variant — the prior
 * CSS-variable + inline `style="background: var(--color-bg)"`
 * approach produced an empty/white panel in the bundled NativePHP
 * context. The test locks the visible contract: the rendered HTML
 * must contain dark-mode background utilities on the panel + rail +
 * input border + foot, and dark-mode text utilities on every
 * non-icon row.
 */

beforeEach(function (): void {
    $user = User::query()->create([
        'username' => 'palette-dark-user',
        'password' => 'fixture-password',
        'theme' => 'dark',
        'is_developer' => true,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $contract = $this->createStub(CurrentUser::class);
    $contract->method('isAuthenticated')->willReturn(true);
    $contract->method('user')->willReturn($user);
    $contract->method('id')->willReturn($user->id);
    app()->instance(CurrentUser::class, $contract);
});

it('renders the palette panel with explicit dark-mode utilities on every surface', function (): void {
    $html = Livewire::test(CommandPaletteModal::class)->html();

    // Panel surface (background + text + ring).
    expect($html)->toContain('bg-white dark:bg-[#0b1220]');
    expect($html)->toContain('text-slate-900 dark:text-slate-100');
    expect($html)->toContain('ring-slate-200 dark:ring-slate-700');

    // Input border + placeholder.
    expect($html)->toContain('border-slate-200 dark:border-slate-700');
    expect($html)->toContain('placeholder:text-slate-400 dark:placeholder:text-slate-500');

    // Foot uses the same border + muted text on both themes.
    expect($html)->toContain('text-slate-500 dark:text-slate-400');
});

it('renders palette rows with explicit dark-mode hover + active state classes', function (): void {
    $html = Livewire::test(CommandPaletteModal::class)->html();

    // Hover state must darken in dark mode and lighten in light mode.
    expect($html)->toContain('hover:bg-slate-100 dark:hover:bg-slate-800');

    // Active state must paint the row with a contrasting surface in
    // both modes — paired with explicit dark text color.
    expect($html)->toContain('bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100');
});

it('emits no inline style attributes that depend on CSS custom properties (regression for the white-panel bug)', function (): void {
    $html = Livewire::test(CommandPaletteModal::class)->html();

    // The previous bug shipped inline `style="background: var(--color-bg, #fff)"`
    // declarations on the panel surfaces; in the bundled NativePHP
    // context they fell through to the #fff fallback even with
    // `<html class="dark">` set. Lock the regression: no inline style
    // attribute may reference `var(--color-bg`, `var(--color-text`,
    // or `var(--color-surface` on this view.
    expect($html)->not->toContain('var(--color-bg');
    expect($html)->not->toContain('var(--color-text');
    expect($html)->not->toContain('var(--color-surface');
});
