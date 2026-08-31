<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;

uses(RefreshDatabase::class);

// Settings grouped its twenty sections under six headings. Two of those groups
// moved here whole — this device and its locks, then where its data comes from
// and goes — and only the sections travelled: the page took eight of them as one
// flat stack, and both headings stayed behind in a lang file nothing read.

function dataAndDevicesUser(): User
{
    return User::query()->create([
        'username' => 'data-devices-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('irrelevant-for-placement'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('names both groups the page took from Settings', function (): void {
    $this->actingAs(dataAndDevicesUser());

    $html = Livewire::test(SyncScreen::class)->assertOk()->html();

    expect($html)
        ->toContain(e(Lang::get('core::settings.groups.security')))
        ->toContain(e(Lang::get('core::settings.groups.data')));
});

it('opens each group above the sections that belong to it', function (): void {
    $this->actingAs(dataAndDevicesUser());

    $html = Livewire::test(SyncScreen::class)->html();

    $security = strpos($html, e(Lang::get('core::settings.groups.security')));
    $data = strpos($html, e(Lang::get('core::settings.groups.data')));
    $appLock = strpos($html, 'data-testid="sync-app-lock"');
    $openBanking = strpos($html, 'data-testid="data-open-banking"');

    // A heading that renders after its own sections labels the next group.
    expect($security)->toBeLessThan($appLock);
    expect($appLock)->toBeLessThan($data);
    expect($data)->toBeLessThan($openBanking);
});
