<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/architecture/navigation-destinations.md
 */

// Shell is the one module that may depend on every other, because it composes
// their screens. That only stays free of cycles while nothing depends back on
// it, so an edge INTO Shell is the one this guard refuses. The route vocabulary
// the features used to reach for lives in Core for exactly this reason.

// The view channel is a separate seam: five providers bind a composer to
// `shell::livewire.app-sidebar` by view name, which no import declares.
// ViewReferencesResolveArchTest is what proves those still resolve.
const SHELL_INBOUND_PINNED = [
    // The palette renders the same roster the rail does, so it reads Shell's
    // resolved rows rather than keeping a second list that can fall behind.
    'Modules/DevMode/Providers/DevModeServiceProvider.php -> Modules\Shell\Public\Navigation\AppNavigation',
    'Modules/DevMode/Providers/DevModeServiceProvider.php -> Modules\Shell\Public\Navigation\ResolvedDestination',
];

/**
 * Every production reference to a `Modules\Shell\` symbol from outside Shell,
 * as `relative/path.php -> Fully\Qualified\Name`.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function shellInboundReferences(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if (str_starts_with($relative, 'Modules/Shell/')) {
            continue;
        }

        $source = str_ends_with($path, '.blade.php')
            ? PatternScan::replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path))
            : implode('', array_map(
                static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
                BackendSourceFiles::codeTokens($path),
            ));

        $normalised = str_replace('\\\\', '\\', $source);
        $matches = PatternScan::all('/Modules\\\\Shell\\\\[A-Za-z0-9_\\\\]+/', $normalised);

        foreach (array_unique($matches[0]) as $symbol) {
            $hits[] = $relative.' -> '.rtrim($symbol, '\\');
        }
    }

    sort($hits);

    return $hits;
}

it('lets nothing outside the Shell module import the Shell module', function (): void {
    // Every root that ships, from the one place a scope is declared. The walk
    // was BackendSourceFiles plus a hand-written second pass over resources/,
    // which is two root lists for one rule and left routes/, config/ and
    // bootstrap/ — three places a provider is wired — outside a guard whose
    // claim is "nothing".
    $files = RepoTree::files(RepoTree::PRODUCTION_PHP);

    // Read before the verdict: this rule compares against a pinned array, and
    // an empty walk fails it loudly — but only because the pin is non-empty.
    // The floor is what holds if the pin is ever emptied. It sits far under
    // today's 6,681.
    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    expect(shellInboundReferences($files))->toBe(
        SHELL_INBOUND_PINNED,
        "Shell composes every other module, so an edge back into it is a cycle.\n".
        "What a feature module wants from Shell is almost always the destination\n".
        "vocabulary, and that lives in Modules\\Core\\Public\\Navigation\\Destination.\n".
        'Re-sort the whole pinned array if a crossing genuinely belongs there.',
    );
});

it('sees a module reaching into the shell', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'shell-inbound').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        use Modules\Shell\Public\Navigation\AppNavigation;
        final class PlantedShellImporter
        {
            public function rows(): array
            {
                return AppNavigation::destinations();
            }
        }
        PHP);

    try {
        $found = shellInboundReferences([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toBe([
        str_replace(base_path().'/', '', $planted).' -> Modules\Shell\Public\Navigation\AppNavigation',
    ]);
});
