<?php

declare(strict_types=1);

/*
 * A donut with dataLabels off AND legend off is a ring of colours that says
 * nothing. On the dashboard there is no axis to carry the meaning and no hover
 * on a phone, so the card gave the user no way to tell which slice was which.
 */

it('never draws a donut with neither a legend nor data labels', function (): void {
    $source = (string) file_get_contents(
        base_path('Modules/Reports/Internal/Http/Livewire/PinnedReportsRow.php'),
    );

    // Isolate the donut builder from the bar/line one above it.
    $start = mb_strpos($source, "'type' => 'donut'");

    expect($start)->not->toBeFalse();

    $donut = mb_substr($source, (int) $start);

    expect($donut)->toContain("'show' => true")
        ->and($donut)->not->toContain("'legend' => ['show' => false]");
});
