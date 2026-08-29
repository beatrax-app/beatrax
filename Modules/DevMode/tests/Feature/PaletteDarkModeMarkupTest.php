<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;

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

    expect($html)->toContain('bg-white dark:bg-[#0b1220]');
    expect($html)->toContain('text-slate-900 dark:text-slate-100');
    expect($html)->toContain('ring-slate-200 dark:ring-slate-700');

    expect($html)->toContain('border-slate-200 dark:border-slate-700');
    expect($html)->toContain('placeholder:text-slate-500 dark:placeholder:text-slate-400');

    expect($html)->toContain('text-slate-500 dark:text-slate-400');
});

it('renders palette rows with explicit dark-mode hover + active state classes', function (): void {
    $html = Livewire::test(CommandPaletteModal::class)->html();

    expect($html)->toContain('hover:bg-slate-100 dark:hover:bg-slate-800');
    expect($html)->toContain('bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100');
});

it('emits no inline style attributes that depend on CSS custom properties (regression for the white-panel bug)', function (): void {
    $html = Livewire::test(CommandPaletteModal::class)->html();

    // Inline `style="background: var(--color-bg, #fff)"` fell through to the
    // #fff fallback in the bundled NativePHP runtime even under
    // `<html class="dark">`, which is where the white panel came from.
    expect($html)->not->toContain('var(--color-bg');
    expect($html)->not->toContain('var(--color-text');
    expect($html)->not->toContain('var(--color-surface');
});
