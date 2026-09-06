<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
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
    // Through PatternScan, which raises rather than answering null. The cast
    // that used to sit on the second call turned a give-up into an empty
    // string, and an empty string is a snapshot that would then be written
    // and locked in as the sidebar.
    $stripped = PatternScan::replace(
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

    $stripped = PatternScan::replace('/-[A-Za-z0-9_-]{8}\.(svg|png|css|js)/', '.$1', $stripped);

    expect($stripped)->toMatchSnapshot();
});
