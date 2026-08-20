<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;

it('renders the brand svg in the authenticated sidebar', function (): void {
    $user = User::query()->create([
        'username' => 'brand-svg-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => false,
    ]);

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    // Vite hashes the built filename, so the src can only be matched by shape.
    expect($html)->toMatch('#/build/assets/logo-[A-Za-z0-9_-]+\.svg#');
    expect($html)->toContain('alt="Beatrax"');
    expect($html)->toContain('logo-svg');

    // The bare "b" is the placeholder glyph the logo replaced; a revert of the
    // brand row would bring it back.
    expect($html)->not->toContain('aria-hidden="true">b</span>');
});
