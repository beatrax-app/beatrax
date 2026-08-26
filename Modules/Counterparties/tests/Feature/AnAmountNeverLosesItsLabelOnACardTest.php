<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// The counterparty card carries two amounts and two labels in one wrapping
// row. Flat, the row broke wherever it ran out of width: "€ 0,00  12 MND
// € 0,00" on the first line and "GEM. / MND" under them, so one amount had its
// label to the right and the other had it underneath, and nothing on the card
// said which belonged to which.

it('makes each amount and its label one item the row can break between', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php')
    );

    // Walking back from each span: the nearest wrapper it opens inside has to
    // be a pair, not the row itself.
    $loose = [];
    foreach (['class="value"', 'class="label"'] as $span) {
        $offset = 0;
        while (($at = strpos($blade, $span, $offset)) !== false) {
            $offset = $at + 1;

            $before = substr($blade, 0, $at);
            if (strrpos($before, 'class="cp-stat"') < strrpos($before, 'class="cp-stats"')) {
                $loose[] = substr_count($before, "\n") + 1;
            }
        }
    }

    sort($loose);

    expect($loose)->toBe([], 'Amounts and labels that can wrap away from each other, at line: '.implode(', ', $loose));

    $pairs = substr_count($blade, 'class="cp-stat"');
    expect($pairs)->toBe(3)
        ->and(substr_count($blade, 'class="value"'))->toBe($pairs)
        ->and(substr_count($blade, 'class="label"'))->toBe($pairs);
});

it('draws that pair as a row of its own', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(CssRule::blockFor($css, '.cp-stat {'))->toContain('display: flex;')
        ->and(CssRule::blockFor($css, '.cp-stat {'))->toContain('align-items: baseline;');

    // At the reader's largest text sizes a pair that cannot fit has to break
    // inside itself rather than take the page sideways.
    expect(CssRule::blockFor($css, ".cp-stat,\n"))->toContain('flex-wrap: wrap;');
});
