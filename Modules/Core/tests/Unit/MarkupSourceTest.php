<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\MarkupParseFailedException;
use Modules\Core\Public\Support\MarkupSource;

// The control group: every case here is one a `[^>]*` or `.*?</tag>` pattern
// answers wrongly, so a green run is evidence the walk reads what the reader
// sees rather than what a pattern could reach.

it('keeps a tag whole when an Alpine expression puts a > inside an attribute', function (): void {
    $source = '<button x-show="count > 3" aria-label="Save">Save</button>';

    $buttons = MarkupSource::elements($source, 'button');

    expect($buttons)->toHaveCount(1)
        ->and($buttons[0]->attribute('aria-label'))->toBe('Save')
        ->and($buttons[0]->attribute('x-show'))->toBe('count > 3')
        ->and($buttons[0]->inner)->toBe('Save');
});

it('keeps a tag whole across a Blade directive argument holding a fat arrow', function (): void {
    $source = '<a @class(["on" => $active, "off"]) href="/x">Go</a>';

    $links = MarkupSource::elements($source, 'a');

    expect($links)->toHaveCount(1)
        ->and($links[0]->attribute('href'))->toBe('/x')
        ->and($links[0]->text())->toBe('Go');
});

it('keeps a tag whole across an echo holding a comparison', function (): void {
    $source = '<div class="{{ $a > $b ? \'up\' : \'down\' }}" data-role="cell">x</div>';

    $divs = MarkupSource::elements($source, 'div');

    expect($divs)->toHaveCount(1)
        ->and($divs[0]->attribute('data-role'))->toBe('cell');
});

it('reads attributes in any order and in either quoting', function (): void {
    $source = "<form action='/a' method='POST' data-x>ok</form>";

    $form = MarkupSource::elements($source, 'form')[0];

    expect($form->attribute('method'))->toBe('POST')
        ->and($form->hasAttribute('data-x'))->toBeTrue()
        ->and($form->attribute('data-x'))->toBe('');
});

it('closes an element at its own closing tag, not at a nested one', function (): void {
    $source = '<fieldset id="outer"><fieldset id="inner">in</fieldset>out</fieldset>';

    $outer = MarkupSource::elements($source, 'fieldset')[0];

    expect($outer->attribute('id'))->toBe('outer')
        ->and($outer->inner)->toBe('<fieldset id="inner">in</fieldset>out');
});

it('answers null, never an empty string, when the closing tag never arrives', function (): void {
    $element = MarkupSource::elements('<section class="a">forever', 'section')[0];

    expect($element->inner)->toBeNull();
    expect(fn (): string => $element->text())->toThrow(MarkupParseFailedException::class);
});

it('raises rather than skipping a start tag that never closes', function (): void {
    expect(fn (): array => MarkupSource::elements('<button aria-label="Save"', 'button'))
        ->toThrow(MarkupParseFailedException::class);
});

it('raises rather than skipping an attribute value that never closes its quote', function (): void {
    expect(fn (): array => MarkupSource::elements('<button aria-label="Save>text</button>', 'button'))
        ->toThrow(MarkupParseFailedException::class);
});

it('does not read a comparison in prose as an element', function (): void {
    expect(MarkupSource::tags('a < b and c > d'))->toBe([]);
});

it('does not read a tag named inside a comment', function (): void {
    expect(MarkupSource::tags('{{-- <button>x</button> --}}'))->toBe([])
        ->and(MarkupSource::tags('<!-- <button>x</button> -->'))->toBe([]);
});

it('does not read a tag named inside script or style', function (): void {
    $source = '<script>if (a < b) { el.innerHTML = "<span>x</span>"; }</script><p>real</p>';

    expect(array_map(static fn ($tag): string => $tag->name, MarkupSource::tags($source)))
        ->toBe(['script', 'p']);
});

it('treats a void element as empty rather than swallowing what follows it', function (): void {
    $source = '<input type="text" value="a"><p>after</p>';

    $input = MarkupSource::elements($source, 'input')[0];

    expect($input->inner)->toBe('')
        ->and($input->attribute('value'))->toBe('a');
});

it('reads a component tag whose name carries a namespace separator', function (): void {
    $source = '<x-core::th scope="col">Amount</x-core::th>';

    $th = MarkupSource::elements($source, 'x-core::th')[0];

    expect($th->attribute('scope'))->toBe('col')
        ->and($th->text())->toBe('Amount');
});

it('reads visible text without spilling an attribute body into it', function (): void {
    $source = '<span x-data="{ f: (a) => a > 1 }" aria-hidden="true">×</span>Delete';

    expect(trim(MarkupSource::text($source)))->toBe('×Delete');
});

it('keeps an echo in the visible text so a caller can tell it is not static', function (): void {
    expect(trim(MarkupSource::text('<b>{{ $name }}</b>')))->toBe('{{ $name }}');
});

it('reports the line an element opened on', function (): void {
    $source = "<div>\n  <p>one</p>\n\n  <p>two</p>\n</div>";

    $paragraphs = MarkupSource::elements($source, 'p');

    expect($paragraphs[0]->line($source))->toBe(2)
        ->and($paragraphs[1]->line($source))->toBe(4);
});

it('splits a class list into its tokens', function (): void {
    $element = MarkupSource::elements("<div class=\"a  b\n c\">x</div>", 'div')[0];

    expect($element->classes())->toBe(['a', 'b', 'c']);
});

// The control group for the source reading: the patterns these replaced are
// shown answering wrongly about the same markup, in both directions.

it('reads a start tag the [^>] pattern cuts in half', function (): void {
    $source = '<button x-on:click="count > 3 ? open() : close()" aria-label="Close">Save</button>';

    $cut = preg_match('~<button\b([^>]*)>~', $source, $found) === 1 ? $found[1] : '';

    expect(str_contains($cut, 'aria-label'))->toBeFalse('the pattern this replaced never reaches the label')
        ->and(MarkupSource::elements($source, 'button')[0]->attribute('aria-label'))->toBe('Close');
});

it('does not count a tag that only exists inside a comment', function (): void {
    $source = '{{-- <x-core::alert role="group"> --}}<p>real</p>';

    $countedOne = preg_match_all('/<x-[a-zA-Z0-9_.:-]+((?:[^>])*?)\/?>/s', $source) === 1;

    expect($countedOne)->toBeTrue('the pattern this replaced counts the commented-out tag')
        ->and(MarkupSource::tags($source))->toHaveCount(1)
        ->and(MarkupSource::tags($source)[0]->name)->toBe('p');
});
