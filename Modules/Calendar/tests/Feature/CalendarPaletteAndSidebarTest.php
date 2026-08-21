<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Navigation\NavigationRegistryImpl;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

function cpsUser(string $suffix = 'cps'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('renders a link to the calendar route in the authenticated app sidebar', function (): void {
    $user = cpsUser('cps-sidebar');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('Calendar');
    $component->assertSee('/calendar', escape: false);
});

it('includes a calendar.index entry in the palette nav roster', function (): void {
    /** @var NavigationRegistry $registry */
    $registry = app(NavigationRegistry::class);

    expect($registry)->toBeInstanceOf(NavigationRegistryImpl::class);

    $ids = array_map(static fn (NavigationEntry $e): string => $e->id, $registry->all());

    expect($ids)->toContain('calendar.index');
});
