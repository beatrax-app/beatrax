<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\AppSidebar;

it('matches the rendered sidebar HTML for a developer (snapshot lock)', function (): void {
    $user = User::query()->create([
        'username' => 'snap-dev',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    // wire:id, wire:snapshot, wire:effects and the CSRF token fluctuate per
    // render, so a snapshot that kept them would never match twice.

    // Vite's content hash goes with them: it is not structure, and leaving it
    // in meant editing any brand asset failed this sidebar test for a reason
    // that has nothing to do with the sidebar.
    $stripped = preg_replace(
        [
            '/\swire:id="[^"]*"/',
            '/\swire:snapshot="[^"]*"/',
            '/\swire:effects="[^"]*"/',
            '/\swire:key="[^"]*"/',
            '/<input\s+type="hidden"\s+name="_token"\s+value="[^"]*"\s+autocomplete="off">/',
        ],
        '',
        $html,
    );

    $stripped = preg_replace('/-[A-Za-z0-9_-]{8}\.(svg|png|css|js)/', '.$1', (string) $stripped);

    expect($stripped)->toMatchSnapshot();
});
