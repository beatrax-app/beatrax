<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Tests\Contracts\Support\RepoTree;
use Tests\Helpers\CssRule;

// Measured on an iPhone 12 mini: the Gmail OAuth wizard's dialog rendered 407px
// wide on a 375pt screen with 46 elements past the right edge — the consent
// scope URL and "Publish App" both cut off mid-word. 36rem is 576px, so the
// max-width every Flux modal in the tree shares never applied on a phone.

// The variants that begin at a breakpoint wider than a phone. A width utility
// carrying one of these cannot reach the screen the cap is for; one carrying
// none of them overrides the cap on the narrowest device, which is the whole
// defect wearing a Tailwind class instead of a stylesheet.
const FLUX_MODAL_WIDER_THAN_A_PHONE = ['sm:', 'md:', 'lg:', 'xl:', '2xl:'];

/**
 * @return list<string> the width utilities a `<flux:modal>` applies on a phone
 */
function fluxModalWidthEscapesIn(string $source): array
{
    $escapes = [];

    foreach (MarkupSource::elements($source, 'flux:modal') as $modal) {
        foreach ($modal->classes() as $class) {
            $colon = strrpos($class, ':');
            $prefix = $colon === false ? '' : substr($class, 0, $colon + 1);
            $utility = $colon === false ? $class : substr($class, $colon + 1);

            if (preg_match('/^(?:max-w|min-w|w)-/', $utility) !== 1) {
                continue;
            }

            if (! in_array($prefix, FLUX_MODAL_WIDER_THAN_A_PHONE, true)) {
                $escapes[] = $class;
            }
        }
    }

    return $escapes;
}

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

it('caps every Flux dialog at the screen it is drawn on', function (): void {
    expect(CssRule::blockFor($this->css, '[data-flux-modal] > dialog:where([class~="[:where(&)]:max-w-xl"])'))
        ->toContain('max-width: min(36rem, calc(100vw - 1rem));');
});

// min-width always beats max-width, so an uncapped floor puts the overflow back.
it('caps the dialog floor as well as its ceiling', function (): void {
    expect(CssRule::blockFor($this->css, '[data-flux-modal] > dialog:where([class~="[:where(&)]:min-w-xs"])'))
        ->toContain('min-width: min(20rem, calc(100vw - 1rem));');
});

// The cap alone still left three elements outside the dialog: a 46-character
// URL has no break opportunity of its own.
it('lets an unbreakable string inside a dialog wrap', function (): void {
    expect(CssRule::blockFor($this->css, '[data-flux-modal] > dialog p'))
        ->toContain('overflow-wrap: anywhere;');
});

// The rules above are keyed to the two classes Flux puts on the dialog itself,
// so a modal inherits them by construction. What that leaves unguarded, and
// what this covers, is a template setting a width of its own: min-width and an
// unprefixed max-width both beat the cap, and the reader is back where the
// wizard was. Every one of the twelve width classes in the tree today is
// `md:`-prefixed, which is why the cap has held.
it('lets no modal in the tree set a width that reaches the phone', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    expect(count($views))->toBeGreaterThan(
        150,
        'RepoTree returned '.count($views).' Blade views, which is too few to have read the tree.'
    );

    $modals = 0;
    $offenders = [];

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);
        $modals += count(MarkupSource::elements($source, 'flux:modal'));

        foreach (fluxModalWidthEscapesIn($source) as $class) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' — class="'.$class.'"';
        }
    }

    // Read before the verdict: the floor sits far under today's 28 modals, so
    // a walk that found none fails here rather than reporting a capped tree.
    expect($modals)->toBeGreaterThan(
        10,
        'the walk found '.$modals.' <flux:modal> elements, which is too few to be this tree.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These modals set their own width with no breakpoint in front of it, so it applies on the',
        'narrowest phone and overrides the cap in resources/css/app.css:',
        ...$offenders,
        '',
        'Prefix it with a breakpoint — md:max-w-2xl rather than max-w-2xl — so the phone keeps the',
        'min(36rem, calc(100vw - 1rem)) ceiling the dialog is capped at.',
    ]));
});

it('tells a width that reaches the phone from one that starts at a breakpoint', function (): void {
    expect(fluxModalWidthEscapesIn('<flux:modal class="md:max-w-2xl"></flux:modal>'))->toBe([])
        ->and(fluxModalWidthEscapesIn('<flux:modal class="max-w-5xl"></flux:modal>'))->toBe(['max-w-5xl'])
        ->and(fluxModalWidthEscapesIn('<flux:modal class="min-w-xl md:w-lg"></flux:modal>'))->toBe(['min-w-xl'])
        ->and(fluxModalWidthEscapesIn('<flux:modal class="space-y-4"></flux:modal>'))->toBe([])
        ->and(fluxModalWidthEscapesIn('<div class="max-w-5xl"></div>'))->toBe([]);
});
