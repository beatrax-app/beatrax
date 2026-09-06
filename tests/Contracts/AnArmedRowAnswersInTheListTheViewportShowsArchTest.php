<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Two pages draw their rows twice — once for the phone, once for the desktop —
// and a media query hides whichever list the viewport is not. A row action that
// arms a state ($archivingPotId, $archivingGoalId) therefore has to have its
// answer drawn in BOTH: a confirm strip written into the desktop list alone is
// markup the phone never paints, so the button that armed it reads as dead. The
// reader taps archive, the id is set, and the screen does not move.
//
// Goals hit this and was fixed; Pots kept the same defect for the same reason,
// which is why this is derived from the CSS rule rather than a list of files —
// a third page adopting the idiom is covered the day it is written.
const BREAKPOINT_SPLIT_STYLE = '~\.([a-z][a-z0-9]*)-desktop-list \{ display: none !important; \}~';

/**
 * @return list<string>
 */
function armedRowGatesIn(string $blade, int $from, int $to): array
{
    $m = PatternScan::allWithOffsets('~@if \(\$(\w+) === \$\w+->id\)~', $blade);

    $gates = [];

    foreach ($m[1] as $i => $capture) {
        $offset = $m[0][$i][1];

        if ($offset >= $from && $offset < $to) {
            $gates[] = (string) $capture[0];
        }
    }

    return array_values(array_unique($gates));
}

it('draws a row action\'s answer in whichever of the two lists the viewport shows', function (): void {
    $blades = [];

    // Every view a reader is shown, not the one glob depth the two known pages
    // happen to sit at: the rule claims a third page adopting the idiom is
    // covered the day it is written, and a deeper path was outside the old walk.
    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match(BREAKPOINT_SPLIT_STYLE, $source, $m) === 1) {
            $blades[$path] = $m[1];
        }
    }

    // The idiom is spelled in CSS, so a rename would leave this scanning
    // nothing and passing. Both known pages have to be found for it to mean
    // anything, and the count is the assertion that it read a real tree.
    expect($blades)->toHaveCount(2, 'Goals and Pots draw their rows twice and are the two pages this rule is derived from. '
        .'Finding some other number means either a page adopted the idiom and belongs here, or the CSS class was '
        .'renamed and this scanned nothing at all.');

    $unanswered = [];

    foreach ($blades as $path => $prefix) {
        $blade = (string) file_get_contents($path);
        $relative = str_replace(RepoTree::root().'/', '', $path);

        $phoneAt = preg_match('~class="[^"]*'.$prefix.'-phone-list~', $blade, $pm, PREG_OFFSET_CAPTURE) === 1
            ? $pm[0][1] : null;
        $desktopAt = preg_match('~class="[^"]*'.$prefix.'-desktop-list~', $blade, $dm, PREG_OFFSET_CAPTURE) === 1
            ? $dm[0][1] : null;
        $desktopEnd = strpos($blade, 'end .'.$prefix.'-desktop-list');

        // The regions are read off three landmarks in document order. If a page
        // ever puts the desktop list first, or drops the end marker, the ranges
        // below would silently cover the wrong text — so refuse instead.
        if ($phoneAt === null || $desktopAt === null || $desktopEnd === false
            || ! ($phoneAt < $desktopAt && $desktopAt < $desktopEnd)) {
            $unanswered[] = $relative.' — the phone list, the desktop list and the end marker are not in document order';

            continue;
        }

        $phoneGates = armedRowGatesIn($blade, $phoneAt, $desktopAt);
        $desktopGates = armedRowGatesIn($blade, $desktopAt, (int) $desktopEnd);

        foreach (array_diff($desktopGates, $phoneGates) as $gate) {
            $unanswered[] = $relative.' — $'.$gate.' is answered in the desktop list only, and the phone hides it';
        }

        foreach (array_diff($phoneGates, $desktopGates) as $gate) {
            $unanswered[] = $relative.' — $'.$gate.' is answered in the phone list only, and the desktop hides it';
        }
    }

    expect($unanswered)->toBe([], "A row action arms a state whose answer the viewport cannot paint:\n  ".implode("\n  ", $unanswered));
});

it('reads the gates of one region without borrowing the other one', function (): void {
    $blade = <<<'BLADE'
        <div class="a-phone-list">
            @if ($archivingPotId === $pot->id)
        </div>
        <div class="a-desktop-list">
            @if ($archivingGoalId === $goal->id)
        {{-- end .a-desktop-list --}}
        BLADE;

    $phoneAt = (int) strpos($blade, 'a-phone-list');
    $desktopAt = (int) strpos($blade, 'a-desktop-list');
    $desktopEnd = (int) strpos($blade, 'end .a-desktop-list');

    expect(armedRowGatesIn($blade, $phoneAt, $desktopAt))
        ->toBe(['archivingPotId'], 'the phone region answers for its own gate and for nothing written below it');

    expect(armedRowGatesIn($blade, $desktopAt, $desktopEnd))
        ->toBe(['archivingGoalId'], 'a gate drawn in the desktop list only is exactly what the rule reports');

    expect(armedRowGatesIn($blade, $desktopEnd, strlen($blade)))
        ->toBe([], 'past the end marker there is no list left to answer in');
});
