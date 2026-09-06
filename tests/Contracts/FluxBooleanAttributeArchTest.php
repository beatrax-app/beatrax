<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-unbound-false-is-the-string-false
 */

// The attributes Flux resolves with ===, so a string never matches.
const STRICTLY_COMPARED_FLUX_ATTRIBUTES = ['dismissible', 'escapable'];

/**
 * Read off parsed elements rather than a line at a time. The first version
 * matched a pattern against any line holding `flux:`, which is blind to the
 * spelling every modal in this tree actually uses -- the attribute on its own
 * continuation line, with the tag name three lines above it.
 *
 * @return list<string> `<tag attribute="value">` for each one passed as a string
 */
function fluxBooleanAttributeOffendersIn(string $source): array
{
    $offenders = [];

    foreach (MarkupSource::tags($source) as $element) {
        if (! str_starts_with(strtolower($element->name), 'flux:')) {
            continue;
        }

        foreach (STRICTLY_COMPARED_FLUX_ATTRIBUTES as $attribute) {
            $value = $element->attribute($attribute);

            if ($value === 'true' || $value === 'false') {
                $offenders[] = '<'.$element->name.' '.$attribute.'="'.$value.'">';
            }
        }
    }

    return $offenders;
}

it('binds every boolean flux attribute with a colon (Flux compares them strictly)', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    // Both denominators before the verdict: an empty walk, and a walk that
    // parsed no element, each report the same clean tree a correctly bound one
    // does. The floors sit far under today's 279 views and 5,944 elements.
    expect(count($views))->toBeGreaterThan(
        150,
        'RepoTree returned '.count($views).' Blade views, which is too few to have read the tree.'
    );

    $offenders = [];
    $elements = 0;

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);
        $elements += count(MarkupSource::tags($source));

        foreach (fluxBooleanAttributeOffendersIn($source) as $offender) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' '.$offender;
        }
    }

    expect($elements)->toBeGreaterThan(
        1000,
        'the lexer returned '.$elements.' elements over '.count($views).' views, which is too few to have parsed them.'
    );

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['These flux attributes are passed as strings, so Flux\'s === check never matches',
            'and the behaviour they ask for is silently skipped. Add the colon:',
            'dismissible="false" -> :dismissible="false". Offenders:'],
        $offenders,
    )));
});

// The tree holds none of what this looks for, so the reader is driven against
// planted markup. The near-misses are the two shapes the tree really carries:
// the bound spelling, and the Blade comment that explains the prop in prose.
it('tells an unbound attribute from the bound one and from the comment describing it', function (): void {
    expect(fluxBooleanAttributeOffendersIn('<flux:modal dismissible="false"></flux:modal>'))
        ->toBe(['<flux:modal dismissible="false">'])
        ->and(fluxBooleanAttributeOffendersIn("<flux:modal\n    name=\"x\"\n    escapable=\"true\"></flux:modal>"))
        ->toBe(['<flux:modal escapable="true">'])
        ->and(fluxBooleanAttributeOffendersIn('<flux:modal :dismissible="false"></flux:modal>'))->toBe([])
        ->and(fluxBooleanAttributeOffendersIn('{{-- dismissible="false" so Esc does not close it --}}'))->toBe([])
        ->and(fluxBooleanAttributeOffendersIn('<div dismissible="false"></div>'))->toBe([]);
});
