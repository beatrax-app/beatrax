<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// `aria-describedby` naming an id nothing emits is silent: the sentence is on
// the screen, the attribute is in the markup, and a reader who hears the page
// is told nothing at all. Measured on /settings before this rule: every
// opening-balance input pointed at `opening-help-<account>` while the paragraph
// beside it carried no id, so the only occurrence of that name in the whole
// tree was the pointer itself.
//
// Read with MarkupSource rather than a tag-shaped pattern, which is the rule
// AGuardThatReadsMarkupParsesItArchTest holds and which duly caught the first
// version of this file. Parsing also answers two questions a pattern had to be
// taught by hand: the lexer does not look inside a Blade comment, and the bound
// form arrives under its own name, `:aria-describedby`, whose value is a PHP
// expression rather than a list of ids. 289 views, 6,118 elements, 0.37s.
//
// What is still matched on the literal, and why it can be: `id="x-{{ $y }}"`
// and `aria-describedby="x-{{ $y }}"` are the same string in the source. A
// value that OPENS with an echo is an expression with nothing to anchor on and
// is skipped; a value with a static prefix names an id written in a loop over a
// DIFFERENT variable -- `aria-labelledby="counterparty-tab-{{ $activeTab }}"`
// against `id="counterparty-tab-{{ $tab }}"` -- so those match on the prefix.
// That still catches what this rule exists for: no id anywhere in the tree
// carried the prefix `opening-help-`.

const ARIA_IDREF_ATTRIBUTES = [
    'aria-labelledby', 'aria-describedby', 'aria-controls',
    'aria-owns', 'aria-details', 'aria-errormessage', 'aria-flowto',
];

function ariaStaticPrefix(string $value): string
{
    foreach (['{{', '{!!'] as $opener) {
        $at = strpos($value, $opener);

        if ($at !== false) {
            return substr($value, 0, $at);
        }
    }

    return $value;
}

/**
 * @return list<string>
 */
function ariaTokens(string $value): array
{
    $flattened = str_replace(["\n", "\r", "\t"], ' ', $value);

    return array_values(array_filter(explode(' ', $flattened), static fn (string $token): bool => $token !== ''));
}

/**
 * @param  list<string>  $sources
 * @return array{ids: list<string>, prefixes: list<string>, references: list<array{attribute: string, value: string}>}
 */
function ariaIdrefShape(array $sources): array
{
    $ids = [];
    $prefixes = [];
    $references = [];

    foreach ($sources as $source) {
        foreach (MarkupSource::tags($source) as $element) {
            // `field-id` counts: a control drawn as a component is handed its
            // id that way and renders it onto the element a label points at --
            // on the date and time inputs, the button rather than the input.
            foreach (['id', 'field-id'] as $emitter) {
                $id = $element->attribute($emitter);

                if ($id !== null) {
                    $ids[] = $id;
                    $prefixes[] = ariaStaticPrefix($id);
                }
            }

            foreach (ARIA_IDREF_ATTRIBUTES as $attribute) {
                $value = $element->attribute($attribute);

                if ($value !== null) {
                    $references[] = ['attribute' => $attribute, 'value' => $value];
                }
            }

            $for = strtolower($element->name) === 'label' ? $element->attribute('for') : null;

            if ($for !== null) {
                $references[] = ['attribute' => 'label for', 'value' => $for];
            }
        }

        // A component can carry its id as a prop default instead of writing the
        // attribute -- `'id' => 'password-requirements'` in an @props block is
        // the target the password field names, and `'fieldId' =>` is what a
        // `<label for>` on a date or locale control points at. Neither is an
        // element, so this one is a question about a string.
        foreach (PatternScan::sets("/'(?:id|fieldId)'\s*=>\s*'([^']+)'/", $source) as $set) {
            $ids[] = $set[1];
            $prefixes[] = ariaStaticPrefix($set[1]);
        }
    }

    return ['ids' => $ids, 'prefixes' => $prefixes, 'references' => $references];
}

/**
 * @param  array{ids: list<string>, prefixes: list<string>, references: list<array{attribute: string, value: string}>}  $shape
 * @return list<string>
 */
function ariaDanglingIn(array $shape): array
{
    $ids = array_flip($shape['ids']);
    $prefixes = array_flip(array_filter($shape['prefixes'], static fn (string $prefix): bool => $prefix !== ''));
    $dangling = [];

    foreach ($shape['references'] as $reference) {
        $value = trim($reference['value']);

        if ($value === '' || str_starts_with($value, '{{') || str_starts_with($value, '{!!')) {
            continue;
        }

        if (str_contains($value, '{{') || str_contains($value, '{!!')) {
            if (! isset($prefixes[ariaStaticPrefix($value)])) {
                $dangling[] = sprintf('%s="%s"', $reference['attribute'], $value);
            }

            continue;
        }

        foreach (ariaTokens($value) as $token) {
            if (! isset($ids[$token])) {
                $dangling[] = sprintf('%s="%s"', $reference['attribute'], $token);
            }
        }
    }

    return $dangling;
}

/**
 * @return array{ids: list<string>, prefixes: list<string>, references: list<array{attribute: string, value: string}>}
 */
function ariaTreeShape(): array
{
    return ariaIdrefShape(array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        RepoTree::files(RepoTree::EVERY_BLADE_VIEW),
    ));
}

it('catches a pointer at an id nothing emits, so a clean tree means something', function (): void {
    $planted = ariaIdrefShape([
        '<p id="real-help">x</p><input aria-describedby="real-help">',
        '<input aria-describedby="never-emitted-anywhere">',
        '<label for="also-missing">L</label>',
        // The defect this rule was written for, in its own shape.
        '<p class="help">x</p><input aria-describedby="opening-help-{{ $accountId }}">',
    ]);

    expect(ariaDanglingIn($planted))->toBe([
        'aria-describedby="never-emitted-anywhere"',
        'label for="also-missing"',
        'aria-describedby="opening-help-{{ $accountId }}"',
    ]);
});

it('passes a prefix whose id is written in a loop over another variable', function (): void {
    expect(ariaDanglingIn(ariaIdrefShape([
        '<h2 id="counterparty-tab-{{ $tab->value }}">x</h2>',
        '<div aria-labelledby="counterparty-tab-{{ $activeTab }}"></div>',
    ])))->toBe([]);
});

it('reads neither a Blade comment nor the bound form, which are not references', function (): void {
    expect(ariaDanglingIn(ariaIdrefShape([
        '{{-- a note about <label for="ghost-in-a-comment">x</label> --}}',
        '<input :aria-describedby="$error !== \'\' ? \'some-error\' : null">',
    ])))->toBe([]);
});

it('reads enough of the tree that a pass is not an empty walk', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);
    $shape = ariaTreeShape();

    expect(count($views))->toBeGreaterThan(200)
        ->and(count($shape['ids']))->toBeGreaterThan(100)
        ->and(count($shape['references']))->toBeGreaterThan(80);
});

it('points every aria reference and every label at an id some view emits', function (): void {
    expect(ariaDanglingIn(ariaTreeShape()))->toBe([]);
});
