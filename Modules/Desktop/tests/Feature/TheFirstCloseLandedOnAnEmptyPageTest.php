<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// Livewire hands a page component's HTML to the layout as $slot, and
// layouts.app is a @yield('content') layout, so a component that does not
// declare the section renders into nothing. Every other full-page component
// in the module calls $view->extends('layouts.app', ...); the close prompt did
// not, and the route Electron navigates to on a first window close served the
// app shell with an empty main. Livewire::test() never sees a layout, so the
// component's own suite stayed green throughout.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'close-prompt-http',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('serves the close prompt through the route the window close navigates to', function (): void {
    $this->get(route('desktop.close-prompt'))
        ->assertOk()
        ->assertSee(Lang::get('desktop::screens.close.title'))
        ->assertSee(Lang::get('desktop::screens.close.button_quit'))
        ->assertSee(Lang::get('desktop::screens.close.button_keep_in_tray'))
        ->assertSee(Lang::get('desktop::screens.close.checkbox_remember'));
});

it('renders the prompt inside the shell rather than replacing it', function (): void {
    $this->get(route('desktop.close-prompt'))
        ->assertOk()
        ->assertSee(Lang::get('desktop::screens.close.title'))
        ->assertSee('Dashboard');
});
