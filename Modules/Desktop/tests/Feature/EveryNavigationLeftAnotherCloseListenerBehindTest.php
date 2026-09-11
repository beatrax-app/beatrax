<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\RenderedMarkup;

// A wire:navigate swap re-runs every script the incoming body carries unless the
// tag is marked data-navigate-once, and the listener this one binds sits on the
// window rather than in the tree that is replaced. Unmarked, each navigation
// left another live listener, each of which POSTs the choice on its own.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'close-listener-once',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('serves the close-window listener on a tag a navigate swap will not run a second time', function (): void {
    $html = (string) $this->get(route('desktop.close-prompt'))->assertOk()->getContent();

    $binding = array_values(array_filter(
        RenderedMarkup::of($html)->all('script'),
        static fn (RenderedMarkup $tag): bool => str_contains($tag->html(), 'close-window-choice'),
    ));

    expect($binding)->toHaveCount(1, 'the layout binds the close-window listener from exactly one script tag');

    expect($binding[0]->attribute('data-navigate-once'))->not->toBeNull(
        'Without data-navigate-once Livewire re-runs this tag on every wire:navigate and leaves another live '
            .'close-window-choice listener on the window, so one answered prompt POSTs the choice once per listener.',
    );
});
