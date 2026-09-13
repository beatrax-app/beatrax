<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Tests\Contracts\Support\RepoTree;

// `x-core::fx-disclosure` renders a sentence, not a figure: a short date, the
// age of the rate in the reader's own language, and the name of the source. Its
// width is a property of the translation and of how old the snapshot is, and on
// a bundled install it is the longest thing on whatever row it lands.
//
// Inside a box declared shrink-0 that sentence sets the row's width. Measured
// at 390x844 on the dashboard net-worth breakdown, where the disclosure sat
// inside the shrink-0 figure: two lines rendered their account-name span 0px
// wide and their figure to 569px and 580px past a 390px viewport, with the
// reader shown a converted amount belonging to no account they could name.
//
// A figure may refuse to shrink — a formatted amount has no break opportunity
// inside it, so shrinking its box overflows it visibly. A sentence has break
// opportunities everywhere and wants them used.

const DISCLOSURE_MOUNT = '<x-core::fx-disclosure';

// Utilities the two roots spell this with. Both, because a rename of one leaves
// a rule that reads the tree and reports it clean.
const DISCLOSURE_UNSHRINKABLE = ['shrink-0', 'flex-shrink-0'];

// The two sites this rule was written from, and the reason it can report an
// empty offender list honestly: one template mounts a disclosure and one
// declares a box that refuses to shrink. A walk that reaches neither has
// answered nothing, whatever the offender list says.
const DISCLOSURE_WALK_PROOF = 'Modules/Shell/Resources/views/livewire/net-worth-card.blade.php';

/**
 * @return array{offenders: list<string>, boxIn: list<string>, mountIn: list<string>}
 */
function disclosureBoxesThatWillNotShrink(): array
{
    $offenders = [];
    $boxIn = [];
    $mountIn = [];

    foreach (RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW) as $relative) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);

        if (! str_contains($source, DISCLOSURE_MOUNT)) {
            continue;
        }

        $mountIn[] = $relative;

        // Parsed rather than matched: a class attribute here holds `{{ }}`, and
        // the `->` inside one ends a `[^>]*` tag pattern three characters in.
        foreach (['span', 'div', 'p', 'td', 'li'] as $tag) {
            foreach (MarkupSource::elements($source, $tag) as $element) {
                if (array_intersect(DISCLOSURE_UNSHRINKABLE, $element->classes()) === []) {
                    continue;
                }

                $boxIn[] = $relative;

                if ($element->inner !== null && str_contains($element->inner, DISCLOSURE_MOUNT)) {
                    $offenders[] = $relative.':'.$element->line($source);
                }
            }
        }
    }

    sort($offenders);

    return [
        'offenders' => $offenders,
        'boxIn' => array_values(array_unique($boxIn)),
        'mountIn' => array_values(array_unique($mountIn)),
    ];
}

it('never mounts a disclosure inside a box that refuses to shrink', function (): void {
    $walk = disclosureBoxesThatWillNotShrink();

    // Both halves of the question, proved against one template that answers yes
    // to each: either reading empty makes the offender list below "clean"
    // without anything having been compared. in_array rather than toContain,
    // which takes needles — a message beside one becomes a second needle.
    expect(in_array(DISCLOSURE_WALK_PROOF, $walk['mountIn'], true))->toBeTrue(
        'The walk read no disclosure mount in '.DISCLOSURE_WALK_PROOF.', so it is not reaching the templates.',
    );

    expect(in_array(DISCLOSURE_WALK_PROOF, $walk['boxIn'], true))->toBeTrue(
        'The walk found no shrink-0 box in '.DISCLOSURE_WALK_PROOF.', so the parse is not reaching class attributes.',
    );

    expect($walk['offenders'])->toBe(
        [],
        "These boxes refuse to shrink around a disclosure, so the sentence inside them sets the row's width and\n"
        ."whatever else is on the row gives way. Move the disclosure out of the shrink-0 box:\n  "
        .implode("\n  ", $walk['offenders']),
    );
});
