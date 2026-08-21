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
