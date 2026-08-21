<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// The name a reader hears is not any one attribute, so asserting attributes
// proves nothing: aria-labelledby outranks aria-label, which outranks the
// caller's <label for="…">, which outranks the button's own text. These
// helpers walk that order over the rendered markup and answer with the string
// itself. Confirmed against Chrome on the same markup: an aria-label field
// holding 31-12-2026 computes to "Choose a date" and nothing else.

/** The `display` getter is what Alpine writes into an `x-text` node once it hydrates. */
function bxDateTimeXText(string $expression, string $display): string
{
    if ($expression === 'display') {
        return $display;
    }

    if (preg_match('/^display \|\| (.+)$/', trim($expression), $matches) !== 1) {
        throw new RuntimeException('unmodelled x-text expression: '.$expression);
    }

    $fallback = json_decode(trim($matches[1]));

    return $display !== '' ? $display : (is_string($fallback) ? $fallback : trim(trim($matches[1]), "'\""));
}

function bxDateTimeNodeText(DOMElement $node, string $display, bool $isRoot): string
{
    if (! $isRoot && $node->getAttribute('aria-hidden') === 'true') {
        return '';
    }

    if ($node->getAttribute('x-text') !== '') {
        return bxDateTimeXText($node->getAttribute('x-text'), $display);
    }

    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text .= $child->textContent;
        } elseif ($child instanceof DOMElement) {
            $text .= ' '.bxDateTimeNodeText($child, $display, false);
        }
    }

    return $text;
}

/** @param  string  $display  the value the field holds after hydration; '' is the empty field */
function bxDateTimeAccessibleName(string $html, string $display = ''): string
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="UTF-8"?><div>'.$html.'</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $button = $xpath->query('//button')->item(0);
    expect($button)->toBeInstanceOf(DOMElement::class);
    /** @var DOMElement $button */
    $normalise = static fn (string $raw): string => trim((string) preg_replace('/\s+/u', ' ', $raw));

    $labelledBy = trim($button->getAttribute('aria-labelledby'));
    if ($labelledBy !== '') {
        $parts = [];
        foreach (preg_split('/\s+/', $labelledBy) ?: [] as $reference) {
            $target = $xpath->query('//*[@id="'.$reference.'"]')->item(0);
            expect($target)->toBeInstanceOf(DOMElement::class);
            /** @var DOMElement $target */
            $parts[] = bxDateTimeNodeText($target, $display, true);
        }

        return $normalise(implode(' ', $parts));
    }

    $ariaLabel = trim($button->getAttribute('aria-label'));
    if ($ariaLabel !== '') {
        return $normalise($ariaLabel);
    }

    $id = $button->getAttribute('id');
    if ($id !== '') {
        $label = $xpath->query('//label[@for="'.$id.'"]')->item(0);
        if ($label instanceof DOMElement) {
            return $normalise(bxDateTimeNodeText($label, $display, true));
        }
    }

    return $normalise(bxDateTimeNodeText($button, $display, true));
}

it('names an unlabelled date field with what it is and what it holds', function (): void {
    $html = Blade::render('<x-core::date-input wire:model="targetDate" />');

    expect(bxDateTimeAccessibleName($html))->toBe('Choose a date no date chosen')
        ->and(bxDateTimeAccessibleName($html, '31-12-2026'))->toBe('Choose a date 31-12-2026');
});

it('keeps a caller aria-label as the date field name and still announces the value', function (): void {
    $from = Blade::render('<x-core::date-input wire:model.live="filterAfter" aria-label="Date from" />');
    $to = Blade::render('<x-core::date-input wire:model.live="filterBefore" aria-label="Date to" />');

    expect(bxDateTimeAccessibleName($from))->toBe('Date from no date chosen')
        ->and(bxDateTimeAccessibleName($to))->toBe('Date to no date chosen')
        ->and(bxDateTimeAccessibleName($from, '01-01-2026'))->toBe('Date from 01-01-2026')
        ->and(bxDateTimeAccessibleName($to, '31-01-2026'))->toBe('Date to 31-01-2026');
});

it('outranks the label a caller points at the date button', function (): void {
    $html = Blade::render(
        '<label for="goal-date">Target date</label><x-core::date-input field-id="goal-date" wire:model="targetDate" />'
    );

    expect(bxDateTimeAccessibleName($html))->toBe('Choose a date no date chosen')
        ->and(bxDateTimeAccessibleName($html, '31-12-2026'))->toBe('Choose a date 31-12-2026');
});

it('names an unlabelled time field with what it is and what it holds', function (): void {
    $html = Blade::render('<x-core::time-input wire:model="quietHoursFrom" />');

    expect(bxDateTimeAccessibleName($html))->toBe('Choose a time no time chosen')
        ->and(bxDateTimeAccessibleName($html, '21:30'))->toBe('Choose a time 21:30');
});

it('outranks the label a caller points at the time button', function (): void {
    $html = Blade::render(
        '<label for="quietHoursFrom">Quiet hours from</label><x-core::time-input field-id="quietHoursFrom" wire:model="quietHoursFrom" />'
    );

    expect(bxDateTimeAccessibleName($html))->toBe('Choose a time no time chosen')
        ->and(bxDateTimeAccessibleName($html, '21:30'))->toBe('Choose a time 21:30');
});

it('speaks the empty field in the reader locale rather than as an em dash', function (): void {
    app()->setLocale('nl');

    $date = Blade::render('<x-core::date-input wire:model="targetDate" />');
    $time = Blade::render('<x-core::time-input wire:model="quietHoursFrom" />');

    expect(bxDateTimeAccessibleName($date))->toBe('Kies een datum geen datum gekozen')
        ->and(bxDateTimeAccessibleName($time))->toBe('Kies een tijd geen tijd gekozen')
        ->and(bxDateTimeAccessibleName($date))->not->toContain('—')
        ->and(bxDateTimeAccessibleName($time))->not->toContain('—');
});
