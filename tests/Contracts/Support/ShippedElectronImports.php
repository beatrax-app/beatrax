<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

/**
 * Relative imports in the JavaScript a Composer package ships pre-compiled.
 *
 * A dependency can publish a `dist/` whose modules import a sibling it did not
 * commit. Nothing in a PHP pipeline reads that tree, and the failure surfaces
 * only when a bundler is asked to resolve it — which happens in the release
 * build and nowhere else, so it lands as a broken tag rather than a red PR.
 */
final class ShippedElectronImports
{
    /**
     * Bare specifiers are somebody else's problem: `electron` and `express` are
     * resolved from node_modules the build installs, not from the package.
     *
     * @return array{specifiers: int, unresolved: list<string>}
     */
    public static function scan(string $file, string $source): array
    {
        preg_match_all(
            '/(?:from|import)\s*\(?\s*[\'"](\.[^\'"]*)[\'"]/',
            $source,
            $matches,
        );

        $unresolved = [];

        foreach ($matches[1] as $specifier) {
            if (self::resolves(dirname($file).'/'.$specifier)) {
                continue;
            }

            $unresolved[] = $specifier;
        }

        return ['specifiers' => count($matches[1]), 'unresolved' => $unresolved];
    }

    // The three shapes a bundler tries, in the order it tries them. An
    // extensionless specifier is legal in the CommonJS half of these packages
    // and a directory stands for its index, so neither is a missing file.
    private static function resolves(string $path): bool
    {
        return is_file($path) || is_file($path.'.js') || is_file($path.'/index.js');
    }
}
