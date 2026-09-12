<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;

// The toolbar was copied from the navigation drawer and lost the line that
// makes the drawer's box work. The desktop field shipped `disabled` under a
// hint for a key nothing listens for, and the phone row's Filters button
// carried no wire:click while its field carried no wire:model, so on every
// install, not only a new one, three of the toolbar's controls did nothing.

function cpToolbarReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cpToolbarMarkup(string $username): RenderedMarkup
{
    return RenderedMarkup::of(
        Livewire::actingAs(cpToolbarReader($username))->test(CounterpartyIndex::class)->html()
    );
}

it('gives both widths a search box that opens the palette', function (): void {
    $boxes = cpToolbarMarkup('cp-toolbar-search')->all('.side-search');

    expect($boxes)->toHaveCount(2);

    foreach ($boxes as $box) {
        $input = $box->firstOrFail('input');

        expect($box->attribute('x-on:click'))->toContain('palette:open')
            ->and($input->attribute('x-on:focus'))->toContain('palette:open')
            ->and($input->attribute('readonly'))->not->toBeNull()
            ->and($input->attribute('disabled'))->toBeNull()
            ->and($input->attribute('placeholder'))->toBe(Lang::get('core::sidebar.search_placeholder'))
            ->and($input->attribute('aria-label'))->toBe(Lang::get('core::sidebar.search_aria'));
    }
});

it('drops the filter row whose button opened nothing', function (): void {
    $page = cpToolbarMarkup('cp-toolbar-filters');

    expect($page->has('.filter-trigger-row'))->toBeFalse()
        ->and($page->html())->not->toContain(Lang::get('core::components.open_filters'));
});

// The palette answers the chord, never the slash the old hint advertised, and
// the Mac glyph has to stay a client-side write: a server-rendered U+2318 goes
// to a reader whose keyboard has no such key.
it('advertises the chord the palette listens for rather than a slash', function (): void {
    $page = cpToolbarMarkup('cp-toolbar-kbd');
    $hint = $page->firstOrFail('.kbd');

    expect($hint->text())->toBe('Ctrl+K')
        ->and($hint->attribute('x-text'))->toContain('isMac')
        ->and($page->html())->not->toContain('⌘');
});
