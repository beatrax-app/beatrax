<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// "Drop your ASN CSV here" on a phone names a gesture the device does not
// have. The whole dashed area is the file picker, so the action was always
// there — but the only line describing a reachable one was the small grey
// sublink under a lead that described drag-and-drop.

/**
 * @param  callable(): string  $render
 */
function dropZoneHtmlOnPhone(callable $render): string
{
    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    try {
        return $render();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }
}

it('does not tell a touch device to drop a file on itself', function (): void {
    $html = dropZoneHtmlOnPhone(fn (): string => Blade::render(
        '<x-onboarding::drop-zone :lead="$lead" accept=".csv" />',
        ['lead' => 'Drop your ASN CSV here'],
    ));

    expect($html)->not->toContain('Drop your ASN CSV here')
        ->and($html)->toContain('Tap to choose a file');

    // The sublink was the fallback affordance; with the lead now describing
    // the tap, "or browse for a file" has nothing left to be an alternative to.
    expect($html)->not->toContain('or browse for a file');
});

it('keeps the drag-and-drop copy where dragging works', function (): void {
    $html = Blade::render(
        '<x-onboarding::drop-zone :lead="$lead" accept=".csv" />',
        ['lead' => 'Drop your ASN CSV here'],
    );

    expect($html)->toContain('Drop your ASN CSV here')
        ->and($html)->toContain('or browse for a file');
});

// The gesture is what the phone lacks, not the file type. Collapsing every
// variant to "Tap to choose a file" threw away which file the zone wants,
// which is real information the reader has no other source for.
it('keeps the caller s format in the touch copy', function (): void {
    $html = dropZoneHtmlOnPhone(fn (): string => Blade::render(
        '<x-onboarding::drop-zone :lead="$lead" accept=".csv" file-label="ASN CSV" />',
        ['lead' => 'Drop your ASN CSV here'],
    ));

    expect($html)->toContain('Tap to choose your ASN CSV file')
        ->and($html)->not->toContain('Drop your ASN CSV here');
});

it('falls back to the unnamed touch copy when the caller has no one format to name', function (): void {
    $html = dropZoneHtmlOnPhone(fn (): string => Blade::render(
        '<x-onboarding::drop-zone :lead="$lead" accept=".csv" />',
        ['lead' => 'Pick which bank exported your CSV'],
    ));

    expect($html)->toContain('Tap to choose a file');
});

// The ICS step hand-rolled its own <label class="drop-zone">, so the fix for
// the touch copy landed in the component and missed the second copy of it —
// leaving that step telling a phone to drop a PDF on itself.
it('gives the ICS statements step the same touch copy as every other connector', function (): void {
    $html = dropZoneHtmlOnPhone(fn (): string => Blade::render(
        '<x-onboarding::drop-zone wire-model="statements" :lead="$lead" accept=".pdf" file-label="PDF" :multiple="true" />',
        ['lead' => 'Drop your ICS PDFs here'],
    ));

    expect($html)->toContain('Tap to choose your PDF file')
        ->and($html)->not->toContain('Drop your ICS PDFs here')
        ->and($html)->toContain('multiple');
});

it('leaves the ICS step multi-file on a desktop', function (): void {
    $html = Blade::render(
        '<x-onboarding::drop-zone wire-model="statements" :lead="$lead" accept=".pdf" file-label="PDF" :multiple="true" />',
        ['lead' => 'Drop your ICS PDFs here'],
    );

    expect($html)->toContain('Drop your ICS PDFs here')
        ->and($html)->toContain('multiple')
        ->and($html)->toContain('wire:model="statements"');
});
