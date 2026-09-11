<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// A wire:navigate swap replaces the body element and re-runs every script tag
// inside the incoming one that does not carry data-navigate-once. A listener
// bound to window or document is not inside that tree and survives the swap, so
// an unmarked tag that binds one leaves another live listener per navigation.
/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-listener-bound-to-the-window-outlived-the-page-that-bound-it
 */

// Each of these hands a callback to something the swap does not tear down. An
// assignment does not: re-running `window.beatraxIdleMs = …` writes the same
// property twice, which is why the layout's idle-config tag is not on this list
// and needs no attribute.
const OUTLIVES_THE_BODY_SWAP = [
    'addEventListener' => '/\baddEventListener\s*\(/',
    'setTimeout / setInterval' => '/\bset(?:Timeout|Interval)\s*\(/',
    'an observer' => '/\bnew\s+[A-Za-z]*Observer\s*\(/',
    'Livewire.on' => '/\bLivewire\s*\.\s*on\s*\(/',
    'Alpine.effect' => '/\bAlpine\s*\.\s*effect\s*\(/',
];

// A walk that read nothing reports the same clean tree as one that found
// nothing wrong, so the denominators are asserted before the verdict. The tree
// holds 282 templates, six script tags between them, and three that bind.
const BINDING_SCRIPT_FILE_FLOOR = 150;

const BINDING_SCRIPT_TAG_FLOOR = 3;

const BINDING_SCRIPT_SITE_FLOOR = 1;

/**
 * @return list<string> the name of every binding in $body that outlives the swap
 */
function bindingsOutlivingTheSwap(string $body): array
{
    $found = [];

    foreach (OUTLIVES_THE_BODY_SWAP as $name => $pattern) {
        if (PatternScan::matches($pattern, $body)) {
            $found[] = $name;
        }
    }

    return $found;
}

/**
 * Read with the markup lexer rather than with a tag-shaped pattern: `[^>]*`
 * ends a tag at the first `>` an Alpine arrow puts inside an attribute, and a
 * `<script>` named inside an `@php` docblock is not a tag at all.
 *
 * @return list<array{line: int, once: bool, binds: list<string>}>
 */
function bindingScriptsIn(string $source): array
{
    $sites = [];

    foreach (MarkupSource::elements($source, 'script') as $tag) {
        $binds = bindingsOutlivingTheSwap($tag->innerOrFail());

        if ($binds !== []) {
            $sites[] = [
                'line' => $tag->line($source),
                'once' => $tag->hasAttribute('data-navigate-once'),
                'binds' => $binds,
            ];
        }
    }

    return $sites;
}

/**
 * @return array{files: int, tags: int, binding: array<string, list<array{line: int, once: bool, binds: list<string>}>>}
 */
function bindingScriptsInTemplates(): array
{
    $files = 0;
    $tags = 0;
    $binding = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);
        $files++;
        $tags += count(MarkupSource::elements($source, 'script'));

        $found = bindingScriptsIn($source);

        if ($found !== []) {
            $binding[str_replace(RepoTree::root().'/', '', $path)] = $found;
        }
    }

    ksort($binding);

    return ['files' => $files, 'tags' => $tags, 'binding' => $binding];
}

it('marks every Blade script that binds past the body swap so a navigation cannot bind it twice', function (): void {
    $found = bindingScriptsInTemplates();

    expect($found['files'])->toBeGreaterThan(
        BINDING_SCRIPT_FILE_FLOOR,
        'Only '.$found['files'].' templates were opened, which is too few to have read the view tree at all.',
    );
    expect($found['tags'])->toBeGreaterThan(
        BINDING_SCRIPT_TAG_FLOOR,
        'The lexer returned '.$found['tags'].' script tags out of those templates, so it stopped rather than found them clean.',
    );

    $exposed = [];
    $counted = 0;

    foreach ($found['binding'] as $file => $sites) {
        foreach ($sites as $site) {
            $counted++;

            if (! $site['once']) {
                $exposed[] = $file.':'.$site['line'].'  binds '.implode(', ', $site['binds']);
            }
        }
    }

    expect($counted)->toBeGreaterThan(
        BINDING_SCRIPT_SITE_FLOOR,
        'Only '.$counted.' binding script tags were found in the whole view tree, so the verdict below is about markup nobody matched.',
    );

    expect($exposed)->toBe([], implode("\n  ", [
        'These script tags bind something the body swap does not tear down, and Livewire re-runs every '
            .'body script an incoming page carries unless the tag is marked data-navigate-once — so each '
            .'wire:navigate leaves another live listener, timer or observer behind for as long as the tab '
            .'stays open. Add data-navigate-once to the tag; the nonce is exempt from the hash Livewire '
            .'matches on, so a per-request CSP nonce does not defeat it:',
        ...$exposed,
    ]));
});

it('reads a binding that survives the swap and not an assignment that is simply made again', function (): void {
    expect(bindingsOutlivingTheSwap("window.beatraxIdleMs = 900000; window.beatraxLockUrl = '/lock';"))
        ->toBe([], 'an assignment re-run by the swap writes the same property twice and leaves nothing behind');

    expect(bindingsOutlivingTheSwap("window.addEventListener('close-window-choice', post);"))
        ->toBe(['addEventListener'], 'the shape this rule exists for');

    expect(bindingsOutlivingTheSwap("document.documentElement.classList.add('dark');"))
        ->toBe([], 'the pre-paint tag touches the root element and binds nothing');

    expect(bindingsOutlivingTheSwap('const fit = new ResizeObserver(draw); fit.observe(el);'))
        ->toBe(['an observer'], 'an observer started at the top level of a tag has nothing stopping it either');
});

it('goes red on a planted tag and stays quiet on the marked one beside it', function (): void {
    $planted = <<<'BLADE'
<script nonce="x">
    window.addEventListener('close-window-choice', post);
</script>
<script nonce="x" data-navigate-once>
    window.addEventListener('close-window-choice', post);
</script>
<script nonce="x">
    window.beatraxIdleMs = 900000;
</script>
BLADE;

    expect(bindingScriptsIn($planted))->toBe([
        ['line' => 1, 'once' => false, 'binds' => ['addEventListener']],
        ['line' => 4, 'once' => true, 'binds' => ['addEventListener']],
    ]);
});
