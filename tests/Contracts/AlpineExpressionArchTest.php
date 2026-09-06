<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a--comment-leading-an-alpine-expression
 */

/**
 * The roots come from RepoTree rather than from a list of this rule's own: the
 * one it carried opened resources/views, while every view a reader is shown is
 * what the rule is about.
 *
 * @return list<string>
 */
function alpineBladeFiles(): array
{
    return RepoTree::files(RepoTree::EVERY_BLADE_VIEW);
}

/** @return list<string> the directive of every Alpine expression opening with a line comment */
function alpineExpressionsOpeningWithAComment(string $source): array
{
    $found = [];

    foreach (PatternScan::sets(
        '/\b(x-(?:init|effect|show|model|text|html|if|on:[\w.\-]+|bind:[\w.\-]+)|@[\w.\-]+)\s*=\s*"(\s*)\/\//',
        $source,
    ) as $match) {
        $found[] = $match[1];
    }

    return $found;
}

it('never opens an Alpine expression with a line comment', function (): void {
    $files = alpineBladeFiles();

    // Two hundred and seventy-nine templates ship today. A walk that opened
    // none of them would report every expression as sound.
    expect(count($files))->toBeGreaterThan(100, 'Only '.count($files).' Blade templates were read, so this rule proved nothing.');

    $offenders = [];

    foreach ($files as $path) {
        foreach (alpineExpressionsOpeningWithAComment((string) file_get_contents($path)) as $directive) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' → '.$directive;
        }
    }

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        "An Alpine expression cannot start with a `//` comment — the directive throws and\n".
        "silently never runs. Move the comment into a {{-- Blade comment --}} above the\n".
        "element:\n  ".implode("\n  ", array_unique($offenders)),
    );
});

it('reads an expression opened by a comment, and leaves a comment written anywhere else alone', function (): void {
    expect(alpineExpressionsOpeningWithAComment('<div x-init="// arm the poller"></div>'))
        ->toBe(['x-init'], 'the directive that throws is the one the scan has to find');

    expect(alpineExpressionsOpeningWithAComment('<div x-on:click="open = ! open"></div>'))
        ->toBe([], 'an expression with no comment in front of it runs');

    expect(alpineExpressionsOpeningWithAComment('<div x-init="poll() // every ten seconds"></div>'))
        ->toBe([], 'a comment AFTER the expression leaves the statement in front of it running, which is not this defect');

    expect(alpineExpressionsOpeningWithAComment('<a href="https://beatrax.app">x</a>'))
        ->toBe([], 'the // of a URL scheme is not a comment');
});
