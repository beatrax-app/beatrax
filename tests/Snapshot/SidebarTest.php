<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;

/*
 * AppSidebar HTML structure snapshot.
 *
 * Catches accidental drift in the sidebar HTML structure. Locks
 * the rendered shape (section labels + side-item order + dev-block
 * presence + account row composition).
 *
 * The raw Livewire-mounted HTML carries dynamic attributes —
 * wire:id, wire:snapshot, wire:effects, wire:key — that fluctuate
 * per render. We strip those before snapshot-matching so the
 * snapshot is deterministic across runs while still capturing
 * every meaningful structural change.
 */

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

    // Strip Livewire's dynamic wire:* attributes + the CSRF token so
    // the snapshot stays stable across runs. The structural shape
    // (sections, side-items, side-dev-block, account row) is what we
    // care about.

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
