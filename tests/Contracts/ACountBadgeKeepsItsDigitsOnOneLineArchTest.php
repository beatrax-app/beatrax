<?php

declare(strict_types=1);

// Measured on a Galaxy A51 in nl, on the sidebar's unusual-charges row: the
// label "Ongebruikelijke afschrijvingen" takes two lines where en's "Unusual
// charges" takes one, and the count badge beside it -- a flex child with no
// floor under its width -- was squeezed below its own digits. `37` broke to
// `3` over `7` inside a pill 18px high and spilled onto the row beneath. The
// rail never chose either width; the label did.

function sideBadgeRule(): string
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Read before any offset is taken off it: an unreadable stylesheet answers
    // the empty string, and every assertion below passes over one.
    expect(strlen($css))->toBeGreaterThan(
        50000,
        'The stylesheet read back '.strlen($css).' bytes, which is not the sheet this rule measures.',
    );

    $start = strpos($css, '    .side-badge {');

    expect($start)->not->toBeFalse('No .side-badge rule in the stylesheet.');

    $end = strpos($css, "\n    }", (int) $start);

    // A rule with no closing brace would otherwise be read as a negative
    // length, which substr() answers by trimming the tail off the whole sheet.
    expect($end)->not->toBeFalse('The .side-badge rule is never closed.');

    return substr($css, (int) $start, (int) $end - (int) $start);
}

it('gives the count badge a floor under its width and forbids the break', function (): void {
    $rule = sideBadgeRule();

    $missing = [];

    // flex-shrink keeps the label from taking the badge's width; white-space
    // keeps the digits on one line if anything ever takes it anyway. Either
    // alone still draws `37` on two lines under some label.
    foreach (['flex-shrink: 0', 'white-space: nowrap'] as $property) {
        if (! str_contains($rule, $property)) {
            $missing[] = $property;
        }
    }

    expect($missing)->toBe([], 'The badge can be squeezed by its label, missing: '.implode(', ', $missing));
});

// The guard is only worth anything while the badge is a flex child of a row
// that can wrap. Were the rail to stop being a flex row, this test would keep
// passing while guarding nothing, so it reads the container it depends on.
it('reads the container that makes the squeeze possible', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '    .side-item {');

    expect($start)->not->toBeFalse('No .side-item rule, so the badge this guards is no longer in the rail it was measured in.');

    $end = strpos($css, "\n    }", (int) $start);

    expect($end)->not->toBeFalse('The .side-item rule is never closed.');

    expect(substr($css, (int) $start, (int) $end - (int) $start))
        ->toContain('display: flex');
});
