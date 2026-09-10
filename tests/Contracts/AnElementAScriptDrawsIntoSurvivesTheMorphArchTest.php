<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Livewire's morph finishes by removing every live child the incoming HTML has
// no counterpart for, and an SVG a script drew into an empty div has none. The
// wrapper element survives, so `x-init` never runs a second time and nothing
// draws it again: the reader is left a bordered empty box until they reload.
/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-node-a-script-draws-into-that-the-morph-is-allowed-to-empty
 */

// A `new` is what separates a node being drawn into from a node being read.
// Only the first argument counts: `getElementById('ob-public-key').value` reads
// a field's contents, and freezing that field would be a defect of its own.
const RENDERER_CONSTRUCTED = '/\bnew\s+[A-Za-z_$][A-Za-z0-9_$.]*\s*\(/';

// The closing quote is anchored to the parenthesis that follows it, because the
// id is a Blade echo and one of them holds a quote of its own:
// `querySelector('#{{ $card['chartElementId'] }}')`.
const TARGET_BY_SELECTOR = '/querySelector(?:All)?\(\s*[\'"`]#(.+?)[\'"`]\s*\)/';

const TARGET_BY_ID = '/getElementById\(\s*[\'"`](.+?)[\'"`]\s*\)/';

/**
 * The first argument of the call opening at $open, read by counting
 * parentheses: the selector sits inside a nested call, so the first `)` is not
 * the end of the argument and the first `,` inside it is not its edge.
 */
function firstArgumentAt(string $expression, int $open): string
{
    $length = strlen($expression);
    $depth = 0;

    for ($at = $open; $at < $length; $at++) {
        $character = $expression[$at];
        $depth += ($character === '(' ? 1 : 0) - ($character === ')' ? 1 : 0);

        if ($depth === 0 || ($depth === 1 && $character === ',')) {
            return substr($expression, $open + 1, $at - $open - 1);
        }
    }

    return substr($expression, $open + 1);
}

/**
 * @return list<string> the id expression of every element $expression hands to a renderer
 */
function renderTargetIdsIn(string $expression): array
{
    $ids = [];

    foreach (PatternScan::setsWithOffsets(RENDERER_CONSTRUCTED, $expression) as $construction) {
        $opened = $construction[0][1] + strlen($construction[0][0]) - 1;
        $argument = firstArgumentAt($expression, $opened);

        foreach ([TARGET_BY_SELECTOR, TARGET_BY_ID] as $lookup) {
            foreach (PatternScan::sets($lookup, $argument) as $found) {
                $ids[] = trim($found[1]);
            }
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return list<array{id: string, ignored: bool, line: int}>
 */
function renderTargetsIn(string $source): array
{
    $elements = MarkupSource::tags($source);
    $wanted = [];

    foreach ($elements as $element) {
        foreach ($element->attributes() as $value) {
            foreach (renderTargetIdsIn($value) as $id) {
                $wanted[] = $id;
            }
        }
    }

    $targets = [];

    foreach ($elements as $element) {
        $id = $element->attribute('id');

        if ($id === null || ! in_array(trim($id), $wanted, true)) {
            continue;
        }

        // `wire:ignore.self` is a different instruction — it keeps the
        // element's own attributes and morphs its children, which is the half
        // that empties a chart.
        $targets[] = [
            'id' => trim($id),
            'ignored' => $element->hasAttribute('wire:ignore'),
            'line' => $element->line($source),
        ];
    }

    return $targets;
}

/**
 * @return array{files: int, elements: int, targets: array<string, list<array{id: string, ignored: bool, line: int}>>}
 */
function renderTargetsInTemplates(): array
{
    $files = 0;
    $elements = 0;
    $targets = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);
        $files++;
        $elements += count(MarkupSource::tags($source));

        $found = renderTargetsIn($source);

        if ($found !== []) {
            $targets[str_replace(RepoTree::root().'/', '', $path)] = $found;
        }
    }

    ksort($targets);

    return ['files' => $files, 'elements' => $elements, 'targets' => $targets];
}

it('hides every node a script draws into from the morph that would empty it', function (): void {
    $found = renderTargetsInTemplates();

    // Both floors sit far under what the view tree holds — 282 templates and
    // six thousand elements. A reader that stopped short answers the same clean
    // tree as one that found nothing wrong.
    expect($found['files'])->toBeGreaterThan(
        150,
        'Only '.$found['files'].' templates were opened, which is too few to have read the view tree at all.',
    );
    expect($found['elements'])->toBeGreaterThan(
        3000,
        'The lexer returned '.$found['elements'].' elements out of those templates, so it stopped rather than found them clean.',
    );

    $exposed = [];
    $counted = 0;

    foreach ($found['targets'] as $file => $sites) {
        foreach ($sites as $site) {
            $counted++;

            if (! $site['ignored']) {
                $exposed[] = $file.':'.$site['line'].'  id="'.$site['id'].'"';
            }
        }
    }

    expect($counted)->toBeGreaterThan(
        4,
        'Only '.$counted.' render targets were found in the whole view tree, so the verdict below is about markup nobody matched.',
    );

    expect($exposed)->toBe([], implode("\n  ", [
        'A script draws into these elements, and Livewire\'s morph is allowed to empty them: it removes '
            .'every live child the server\'s HTML has no counterpart for, and a drawn SVG never has one. The '
            .'wrapper survives the same morph, so x-init does not run again and nothing redraws it. Put '
            .'wire:ignore on the element itself — not wire:ignore.self, which morphs exactly the children at '
            .'stake — and give the mounted instance an event to refresh its data on:',
        ...$exposed,
    ]));
});

it('reads the node a renderer was constructed onto, and not one an expression merely reads', function (): void {
    expect(renderTargetIdsIn("chart = new window.ApexCharts(\$el.querySelector('#{{ \$chartElementId }}'), opts); chart.render();"))
        ->toBe(['{{ $chartElementId }}'], 'the ordinary mount, with the target inside a nested call');

    expect(renderTargetIdsIn("new window.ApexCharts(\$el.querySelector('#{{ \$card['chartElementId'] }}'), opts)"))
        ->toBe(["{{ \$card['chartElementId'] }}"], 'a Blade echo carrying a quote of its own, which is the form a delimiter-matched pattern loses');

    expect(renderTargetIdsIn("window.beatraxCopy(document.getElementById('ob-public-key').value)"))
        ->toBe([], 'a value read out of a field is not a drawing, and freezing that field would be a defect of its own');

    expect(renderTargetIdsIn("new Foo(bar, document.getElementById('second'))"))
        ->toBe([], 'only the first argument is the node a renderer draws into');

    expect(renderTargetIdsIn("\$el.closest('li').querySelector('input')?.focus()"))
        ->toBe([], 'a selector naming a tag rather than an id names no element this rule is about');
});

it('goes red on a planted target and stays quiet on the one beside it that is ignored', function (): void {
    $planted = <<<'BLADE'
        <div x-data="{ chart: null }" x-init="chart = new window.ApexCharts($el.querySelector('#exposed'), {}); chart.render();">
            <div id="exposed"></div>
        </div>
        <div x-data="{ chart: null }" x-init="chart = new window.ApexCharts($el.querySelector('#covered'), {}); chart.render();">
            <div wire:ignore id="covered"></div>
        </div>
        <div x-data x-on:click="document.getElementById('field').value = ''">
            <input id="field" value="x">
        </div>
        BLADE;

    expect(renderTargetsIn($planted))->toBe([
        ['id' => 'exposed', 'ignored' => false, 'line' => 2],
        ['id' => 'covered', 'ignored' => true, 'line' => 5],
    ]);
});
