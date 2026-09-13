<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

// ApexCharts offers `xaxis.labels.hideOverlappingLabels`, and its test is wrong
// for labels of unequal width: AxesUtils#checkForOverflowingLabels compares the
// distance between two label CENTRES against the PREVIOUS label's whole width
// and never looks at the width of the label it is deciding about. A narrow
// label followed by a wide one passes it. Measured on the dashboard's pinned
// report grouped by counterparty: `Lidl` printed over `Domino's Pizza` by 26px
// at 1440 and `ANA` over `Yamada Denki` by 19px at 390, with the first tick
// losing its leading glyph at both widths; the same chart on /reports
// overlapped `Kappabashi Dougu` and `ANA` by 85px.
//
// One template had answered that with an override of its own and was still
// wrong, because the option it turned on is the broken test. The decision is
// beatraxFitAxisLabels', taken after the chart has drawn, where the boxes are
// facts — and a template that sets its own is a second answer nobody will
// re-measure.

const AXIS_FIT_HELPER = 'beatraxFitAxisLabels';

const AXIS_FIT_SCRIPT = 'resources/js/app.js';

// The option names a template must not carry. Both, because turning either one
// on or off is a claim about fitting that this tree makes in exactly one place.
const AXIS_FIT_OPTIONS = ['hideOverlappingLabels', 'labels.trim', 'trim:'];

/**
 * @return list<string> the templates that decide axis-label fitting for themselves
 */
function axisFitOverridesInTemplates(): array
{
    $offenders = [];

    foreach (RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW) as $relative) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);

        foreach (AXIS_FIT_OPTIONS as $option) {
            if (str_contains($source, $option)) {
                $offenders[] = $relative.' → '.$option;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

it('fits an axis label in one place, and it is not a template', function (): void {
    $script = (string) file_get_contents(RepoTree::root().'/'.AXIS_FIT_SCRIPT);

    // The subject has to exist before its absence elsewhere means anything: a
    // renamed helper leaves every template clean of a rule guarding nothing.
    expect(str_contains($script, 'window.'.AXIS_FIT_HELPER.' = function'))->toBeTrue(
        AXIS_FIT_SCRIPT.' declares no '.AXIS_FIT_HELPER.', so this rule guards a decision nothing takes.',
    );

    expect(axisFitOverridesInTemplates())->toBe(
        [],
        "These templates decide for themselves which axis labels fit. That decision belongs to\n"
        .AXIS_FIT_HELPER.'() in '.AXIS_FIT_SCRIPT.", which measures the drawn boxes:\n  "
        .implode("\n  ", axisFitOverridesInTemplates()),
    );
});

// The helper is wired for every chart rather than for the one card the defect
// was found on: the same overlap was measured on /reports, which had never
// carried an override of its own.
it('wires the fitting onto every chart the app themes', function (): void {
    $script = (string) file_get_contents(RepoTree::root().'/'.AXIS_FIT_SCRIPT);

    $themed = strpos($script, 'window.beatraxApplyChartTheme = function');
    expect($themed)->not->toBeFalse('No beatraxApplyChartTheme to wire the fitting into.');

    // Before the early return the theme helper takes on a light page, or the
    // fitting is wired on half the installs.
    $dark = strpos($script, 'if (!isDark) {', (int) $themed);
    $wired = strpos($script, 'beatraxFitChartLabels(options);', (int) $themed);

    expect($wired)->not->toBeFalse('beatraxApplyChartTheme never wires the axis-label fitting.');
    expect((int) $wired)->toBeLessThan(
        (int) $dark,
        'The fitting is wired after the theme helper returns on a light page, so it never runs there.',
    );
});
