<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

/**
 * Every class name the shipped tree asks about as a STRING rather than as
 * ::class.
 *
 * `Foo::class` is resolved at compile time and PHPStan fails on a name that is
 * not there. A quoted string is checked by nothing, and the answer to a
 * misspelled one is `false` — which is also the answer when the class is
 * genuinely absent, so the caller takes the branch it would have taken anyway.
 *
 * Shared rather than duplicated: the two roots ask different questions of the
 * same list, and a hand-copied list in the second test would have gone on
 * passing while the first one failed.
 *
 * @link ../../../.docs/features/mobile/architecture.md#a-vendor-hard-codes-a-class-name-and-answers-a-miss-with-silence
 */
final class StringNamedClasses
{
    /**
     * @return array<string, list<string>> fully-qualified name => the files asking about it
     */
    public static function lookups(): array
    {
        $found = [];

        foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
            $matches = PatternScan::all(
                '/\b(?:class|interface|enum|trait)_exists\(\s*[\'"]([\\\\A-Za-z0-9_]+)[\'"]/',
                (string) file_get_contents($path),
            );

            foreach ($matches[1] as $name) {
                $found[ltrim(str_replace('\\\\', '\\', $name), '\\')][] = str_replace(RepoTree::root().'/', '', $path);
            }
        }

        ksort($found);

        return $found;
    }
}
