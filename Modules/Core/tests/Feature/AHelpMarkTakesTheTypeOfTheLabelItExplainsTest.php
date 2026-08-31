<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// The mark is lifted by half the cap height of ITS OWN font, so the label and
// the mark have to resolve to one font-size or the lift aims at the wrong text.
// Beside an <h1> that sets its own size the mark inherited the body's instead,
// and the lift was a third of what a 28px heading needed. The size therefore
// sits on a block that holds both, and the heading no longer restates it.

function pageHeadingWithTip(): string
{
    return Blade::render(<<<'BLADE'
        <x-core::page-heading>
            Reconcile
            <x-slot:tip>
                <x-core::help-tip topic="probe" label="Reconcile" body="Two or three sentences, written for a reader." />
            </x-slot:tip>
        </x-core::page-heading>
        BLADE);
}

/** @return DOMXPath over the rendered heading */
function pageHeadingXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML('<!doctype html><html lang="en"><body>'.$html.'</body></html>', LIBXML_NOERROR);

    return new DOMXPath($document);
}

it('puts the heading and its mark under one block that carries the type', function (): void {
    $xpath = pageHeadingXpath(pageHeadingWithTip());

    $wrappers = $xpath->query('//body/div[contains(@class, "text-2xl")]');
    expect($wrappers?->length)->toBe(1, 'The heading and its mark do not share one block carrying the heading type.');

    expect($xpath->query('//body/div[contains(@class, "text-2xl")]/h1')?->length)->toBe(1)
        ->and($xpath->query('//body/div[contains(@class, "text-2xl")]/button[contains(@class, "help-tip")]')?->length)->toBe(1);
});

// A flex item takes its cross-axis position from the row, not from the line of
// text it explains, and `vertical-align` is ignored on one outright.
it('leaves the mark in the inline flow of its label rather than making it a flex item', function (): void {
    $xpath = pageHeadingXpath(pageHeadingWithTip());

    $wrapper = $xpath->query('//body/div[contains(@class, "text-2xl")]')?->item(0);
    expect($wrapper)->toBeInstanceOf(DOMElement::class);
    expect($wrapper->getAttribute('class'))->not->toContain('flex');
});

it('leaves the heading itself with no size of its own, so the two cannot drift', function (): void {
    $xpath = pageHeadingXpath(pageHeadingWithTip());

    $heading = $xpath->query('//h1')?->item(0);
    expect($heading)->toBeInstanceOf(DOMElement::class);
    expect(preg_match('/\btext-(xs|sm|base|md|lg|xl|2xl|3xl)\b/', $heading->getAttribute('class')))->toBe(0);
});

// Measured at 411px: the Turkish recurring title is one line wide and the mark
// is not, so a breakable space dropped the mark alone onto a second line. The
// trim matters as much as the entity — a caller's newline inside the <h1> is a
// breakable space, and it would sit in front of the glue.
it('glues the mark to the last word, rather than letting it wrap alone', function (): void {
    expect(pageHeadingWithTip())->toContain('Reconcile</h1>&nbsp;<button');
});

// A button inside the heading would be read out as part of the heading, and the
// panel is a <div>, which is not phrasing content.
it('keeps the mark and its panel out of the heading element itself', function (): void {
    $xpath = pageHeadingXpath(pageHeadingWithTip());

    expect($xpath->query('//h1//button')?->length)->toBe(0)
        ->and($xpath->query('//h1//div')?->length)->toBe(0);
});

// Thirty-odd headings carry no tip, and none of them may move.
it('draws a heading with no tip exactly as it did before', function (): void {
    $html = trim(Blade::render('<x-core::page-heading>Reconcile</x-core::page-heading>'));

    expect($html)->toStartWith('<h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">')
        ->and($html)->toEndWith('</h1>');
});
