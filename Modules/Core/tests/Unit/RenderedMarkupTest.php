<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\MarkupParseFailedException;
use Modules\Core\Public\Support\RenderedMarkup;

it('reads an element by selector regardless of attribute order', function (): void {
    $html = '<div><button disabled id="save" class="btn">Save</button></div>';

    expect(RenderedMarkup::of($html)->has('button#save[disabled]'))->toBeTrue()
        ->and(RenderedMarkup::of($html)->firstOrFail('#save')->text())->toBe('Save');
});

it('scopes a search to one element rather than to the whole document', function (): void {
    $html = <<<'HTML'
        <section id="tile"><span class="v">7</span></section>
        <section id="other"><span class="v">2</span></section>
        HTML;

    $tile = RenderedMarkup::of($html)->firstOrFail('#tile');

    expect($tile->text())->toBe('7')
        ->and($tile->count('.v'))->toBe(1)
        ->and(RenderedMarkup::of($html)->count('.v'))->toBe(2);
});

it('finds a descendant across nesting the non-greedy pattern would have cut short', function (): void {
    $html = '<fieldset class="a"><fieldset class="inner"><input name="x"></fieldset><input name="unsplit-survivor"></fieldset>';

    $outer = RenderedMarkup::of($html)->firstOrFail('fieldset.a');

    expect($outer->has('input[name="unsplit-survivor"]'))->toBeTrue()
        ->and($outer->firstOrFail('fieldset.inner')->has('input[name="unsplit-survivor"]'))->toBeFalse();
});

it('supplies the tbody the browser implies, so a row query is not quoting-dependent', function (): void {
    $html = '<table><tr><td>a</td><td>b</td></tr></table>';

    expect(RenderedMarkup::of($html)->count('table tbody tr td'))->toBe(2);
});

it('reads text without the markup an attribute holds', function (): void {
    $html = '<p x-data="{ f: (a) => a > 1 }">the <b>whole</b> line</p>';

    expect(RenderedMarkup::of($html)->firstOrFail('p')->text())->toBe('the whole line');
});

it('raises on an empty document rather than answering nothing found', function (): void {
    expect(fn (): RenderedMarkup => RenderedMarkup::of("  \n "))->toThrow(MarkupParseFailedException::class);
});

it('raises when a document that opened an element parsed to nothing', function (): void {
    $utf16 = "\xff\xfe<\x00d\x00i\x00v\x00 \x00i\x00d\x00=\x00\"\x00a\x00\"\x00>\x00x\x00<\x00/\x00d\x00i\x00v\x00>\x00";

    expect(fn (): RenderedMarkup => RenderedMarkup::of($utf16))->toThrow(MarkupParseFailedException::class);
});

it('raises rather than returning null when a required element is absent', function (): void {
    expect(fn (): RenderedMarkup => RenderedMarkup::of('<div>x</div>')->firstOrFail('#missing'))
        ->toThrow(MarkupParseFailedException::class);
});

it('answers null, not an exception, where the caller asked for an optional element', function (): void {
    expect(RenderedMarkup::of('<div>x</div>')->first('#missing'))->toBeNull();
});

// The two shapes this seam replaced, kept as a control group: each is shown
// answering "yes" about a document where the answer is no. A conversion that
// only ever agrees with the pattern it replaced would prove nothing.

it('is not satisfied by a descendant that belongs to a different element', function (): void {
    $html = '<fieldset><legend>Category to keep</legend></fieldset><div><input name="unsplit-survivor"></div><fieldset>other</fieldset>';

    $greedy = preg_match('/<fieldset>.*name="unsplit-survivor".*<\/fieldset>/s', $html) === 1;

    $owning = array_filter(
        RenderedMarkup::of($html)->all('fieldset'),
        static fn (RenderedMarkup $fieldset): bool => $fieldset->has('input[name="unsplit-survivor"]'),
    );

    expect($greedy)->toBeTrue('the pattern this replaced calls the radio contained')
        ->and($owning)->toBe([]);
});

it('is not satisfied by a value that sits outside the element asked about', function (): void {
    $html = '<span data-testid="tile"><span>label</span><span>0</span></span><p><span>2</span></p>';

    $hop = preg_match('#data-testid="tile"[\s\S]*'.'?'.'>2<#', $html) === 1;

    expect($hop)->toBeTrue('the pattern this replaced reaches past the element it named')
        ->and(RenderedMarkup::of($html)->firstOrFail('[data-testid="tile"] span:last-child')->text())->toBe('0');
});
